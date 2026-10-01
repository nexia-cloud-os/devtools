<?php

declare(strict_types=1);

namespace Nexia\Devtools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nexia\AppRuntime\AppPackageMetadataReader;
use Nexia\ResourceReference\ResourceRef;
use Nexia\Signature\SignatureDocumentDataCardinality;
use Nexia\Signature\SignatureDocumentDataLimits;
use Nexia\Signature\SignatureDocumentDataLookupMode;
use Nexia\Devtools\Commands\Concerns\InteractsWithPackageApps;
use Nexia\Devtools\Commands\Concerns\InteractsWithScaffoldWizard;
use RuntimeException;

/**
 * Produces deliberately incomplete, fail-closed App-local source skeletons.
 *
 * The command does not inspect a model, a database table, casts, or fillable
 * attributes. Signature document values are an opt-in App contract, not a
 * reflection of an App's persistence shape.
 */
final class MakePackageSignatureDocumentDataSource extends Command
{
    use InteractsWithPackageApps;
    use InteractsWithScaffoldWizard;

    protected $signature = 'make:signature-data-source
        {package? : Existing App directory}
        {name? : Source name in PascalCase (for example, AssignedAssets)}
        {--source-key= : Canonical source key; defaults to the App key plus the source name}
        {--source-version=1 : Positive source contract version}
        {--subject-resource-key= : Canonical resource key accepted as the signature document subject}
        {--source-resource-key= : Canonical App-owned resource key returned by this source}
        {--cardinality=one : Source cardinality (one or many)}
        {--min-items=1 : Explicit lower bound for returned selected items}
        {--max-items=1 : Explicit upper bound for returned selected items (at most 50)}
        {--stable-sort-key= : Deterministic scalar sort field for many-cardinality sources; defaults to stable_order for many}
        {--lookup-mode=explicit_source_ref : App-owned lookup rule (direct_subject, derived_ref, explicit_source_ref, owner_scoped_query)}
        {--subject-anchor=subject_resource_ref : Query anchor name documented by the skeleton}
        {--source-ref-anchor=explicit_source_ref : Query anchor name documented by the skeleton}
        {--field-key=source_ref : First explicitly declared protected field key}
        {--field-type=resource_ref : First field type; no fields are inferred from App storage}
        {--field-classification=internal : First field classification (public, internal, confidential, restricted)}
        {--field-formatter=resource_label : First field formatter compatible with its type}
        {--label-key= : Explicit App locale key for the source label}
        {--description-key= : Explicit App locale key for the source description}
        {--field-label-key= : Explicit App locale key for the first field label}
        {--write : Write skeletons. Without this flag the command is always a dry run}
        {--force : Overwrite every existing target deterministically; requires --write}';

    protected $description = 'Scaffold an opt-in package-App signature document data source contract';

    public function handle(AppPackageMetadataReader $metadataReader): int
    {
        $packageInput = $this->scaffoldRequiredArgument(
            'package',
            'App directory',
            'App directory is required when running non-interactively.',
        );
        if ($packageInput === null) {
            return self::FAILURE;
        }

        try {
            $package = $this->resolvePackageApp($packageInput, $metadataReader);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($package === null) {
            $this->error("Package App not found at {$this->packageLookupDirectory($packageInput)}.");

            return self::FAILURE;
        }

        $nameInput = $this->scaffoldRequiredArgument(
            'name',
            'Source name (PascalCase)',
            'Source name is required when running non-interactively.',
        );
        if ($nameInput === null) {
            return self::FAILURE;
        }

        $appKey = $package['definition']->appKey;
        $packageDir = $package['directory'];
        $packageNamespace = $this->manifestNamespace($package['definition']);
        $sourceName = Str::studly($nameInput);
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/D', $sourceName)) {
            $this->error('Source name must produce a valid PHP namespace segment.');

            return self::FAILURE;
        }
        $sourceSlug = Str::snake($sourceName);
        $sourceKey = trim((string) $this->option('source-key')) ?: "{$appKey}.{$sourceSlug}";
        $subjectResourceKey = $this->scaffoldRequiredOption(
            'subject-resource-key',
            'Supported subject resource key',
            '--subject-resource-key is required when running non-interactively.',
        );
        $sourceResourceKey = $this->scaffoldRequiredOption(
            'source-resource-key',
            'Source resource key',
            '--source-resource-key is required when running non-interactively.',
        );
        if ($subjectResourceKey === null || $sourceResourceKey === null) {
            return self::FAILURE;
        }

        $cardinality = strtolower(trim((string) $this->option('cardinality')));
        $lookupMode = strtolower(trim((string) $this->option('lookup-mode')));
        $fieldKey = trim((string) $this->option('field-key'));
        $fieldType = strtolower(trim((string) $this->option('field-type')));
        $fieldClassification = strtolower(trim((string) $this->option('field-classification')));
        $fieldFormatter = strtolower(trim((string) $this->option('field-formatter')));
        $subjectAnchor = trim((string) $this->option('subject-anchor'));
        $sourceRefAnchor = trim((string) $this->option('source-ref-anchor'));
        $needsSourceRefAnchor = in_array($lookupMode, [
            SignatureDocumentDataLookupMode::DerivedRef->value,
            SignatureDocumentDataLookupMode::ExplicitSourceRef->value,
        ], true);
        $stableSortKey = trim((string) $this->option('stable-sort-key'));
        if ($cardinality === SignatureDocumentDataCardinality::Many->value && $stableSortKey === '') {
            $stableSortKey = 'stable_order';
        }
        $sourceVersion = $this->positiveIntegerOption('source-version');
        $minItems = $this->nonNegativeIntegerOption('min-items');
        $maxItems = $this->positiveIntegerOption('max-items');

        if (! $this->validResourceKey($appKey, $sourceKey)
            || ! $this->validResourceKey($this->resourceKeyOwner($subjectResourceKey), $subjectResourceKey)
            || ! $this->validResourceKey($appKey, $sourceResourceKey)
            || ! in_array($cardinality, array_column(SignatureDocumentDataCardinality::cases(), 'value'), true)
            || ! in_array($lookupMode, array_column(SignatureDocumentDataLookupMode::cases(), 'value'), true)
            || ! in_array($fieldType, ['string', 'text', 'date', 'datetime', 'boolean', 'integer', 'decimal', 'money', 'resource_ref'], true)
            || ! in_array($fieldClassification, ['public', 'internal', 'confidential', 'restricted'], true)
            || ! $this->formatterMatchesType($fieldType, $fieldFormatter)
            || ! preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/D', $fieldKey)
            || ! preg_match('/\A[a-z][a-z0-9_]*\z/D', $subjectAnchor)
            || ($needsSourceRefAnchor && ! preg_match('/\A[a-z][a-z0-9_]*\z/D', $sourceRefAnchor))
            || $sourceVersion === null
            || $minItems === null
            || $maxItems === null
            || $maxItems > SignatureDocumentDataLimits::MAX_LIST_ITEMS
            || $minItems > $maxItems) {
            $this->error('Source identity, field shape, anchors, cardinality, lookup bounds, or item bounds are invalid. Use canonical keys and 0 <= min-items <= max-items <= 50.');

            return self::FAILURE;
        }
        if ($cardinality === SignatureDocumentDataCardinality::One->value && ($minItems > 1 || $maxItems > 1)) {
            $this->error('One-cardinality sources require 0 <= min-items <= max-items <= 1.');

            return self::FAILURE;
        }
        if ($cardinality === SignatureDocumentDataCardinality::Many->value
            && ! preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/D', $stableSortKey)) {
            $this->error('Many-cardinality sources require a canonical --stable-sort-key.');

            return self::FAILURE;
        }
        if ($cardinality === SignatureDocumentDataCardinality::One->value && $stableSortKey !== '') {
            $this->error('One-cardinality sources must use an empty --stable-sort-key.');

            return self::FAILURE;
        }
        if ($cardinality === SignatureDocumentDataCardinality::Many->value && $fieldKey === $stableSortKey) {
            $this->error('Many-cardinality sources require --field-key and --stable-sort-key to be different explicit fields.');

            return self::FAILURE;
        }

        $labelKey = trim((string) $this->option('label-key')) ?: "{$appKey}.signature_document_data.{$sourceSlug}.label";
        $descriptionKey = trim((string) $this->option('description-key')) ?: "{$appKey}.signature_document_data.{$sourceSlug}.description";
        $fieldLabelKey = trim((string) $this->option('field-label-key')) ?: "{$appKey}.signature_document_data.fields.{$fieldKey}";
        if (! $this->normalizedKey($labelKey) || ! $this->normalizedKey($descriptionKey) || ! $this->normalizedKey($fieldLabelKey)) {
            $this->error('Locale keys must be non-blank normalized App-local translation keys.');

            return self::FAILURE;
        }

        $providerClass = "{$sourceName}SignatureDocumentDataSourceProvider";
        $contributionClass = "{$sourceName}SignatureDocumentDataSource";
        $hasStableOrder = $cardinality === SignatureDocumentDataCardinality::Many->value;
        $replacements = [
            '{{ packageNamespace }}' => $packageNamespace,
            '{{ appKey }}' => $appKey,
            '{{ sourceKey }}' => $sourceKey,
            '{{ sourceVersion }}' => (string) $sourceVersion,
            '{{ subjectResourceKey }}' => $subjectResourceKey,
            '{{ sourceResourceKey }}' => $sourceResourceKey,
            '{{ sourceName }}' => $sourceName,
            '{{ sourceSlug }}' => $sourceSlug,
            '{{ providerClass }}' => $providerClass,
            '{{ contributionClass }}' => $contributionClass,
            '{{ cardinalityCase }}' => $cardinality === 'many' ? 'Many' : 'One',
            '{{ lookupModeCase }}' => $this->lookupModeCase($lookupMode),
            '{{ minItems }}' => (string) $minItems,
            '{{ maxItems }}' => (string) $maxItems,
            '{{ subjectAnchor }}' => $subjectAnchor,
            '{{ sourceRefAnchor }}' => $sourceRefAnchor,
            '{{ defaultSourceRefAnchorArgument }}' => $needsSourceRefAnchor
                ? "defaultSourceRefAnchor: '{$sourceRefAnchor}',"
                : '',
            '{{ sourceRefAnchorConstant }}' => $needsSourceRefAnchor
                ? "\n    private const SOURCE_REF_ANCHOR = '{$sourceRefAnchor}';"
                : '',
            '{{ fieldKey }}' => $fieldKey,
            '{{ fieldTypeCase }}' => $this->enumCase($fieldType),
            '{{ fieldClassificationCase }}' => $this->enumCase($fieldClassification),
            '{{ fieldFormatterCase }}' => $this->enumCase($fieldFormatter),
            '{{ fieldSyntheticValue }}' => $this->syntheticValue($fieldType, $appKey, $sourceResourceKey, $sourceName),
            '{{ labelKey }}' => $labelKey,
            '{{ descriptionKey }}' => $descriptionKey,
            '{{ fieldLabelKey }}' => $fieldLabelKey,
            '{{ stableSortKey }}' => $stableSortKey,
            '{{ manyFields }}' => $hasStableOrder ? $this->manyDescriptorField($appKey, $sourceSlug, $stableSortKey) : '',
            '{{ manySyntheticSample }}' => $hasStableOrder ? "\n                    '{$stableSortKey}' => 1," : '',
            '{{ stableSortArgument }}' => $hasStableOrder ? "stableSortKey: '{$stableSortKey}'," : '',
            '{{ stableOrderConstants }}' => $hasStableOrder ? "\n    private const STABLE_SORT_KEY = '{$stableSortKey}';" : '',
            '{{ manyFieldKey }}' => $hasStableOrder ? ", '{$stableSortKey}'" : '',
        ];

        $files = [
            dirname(__DIR__, 2).'/stubs/signature-data-source/contribution.stub' => "{$packageDir}/src/Descriptors/{$contributionClass}.php",
            dirname(__DIR__, 2).'/stubs/signature-data-source/provider.stub' => "{$packageDir}/src/Signature/{$providerClass}.php",
        ];

        $write = (bool) $this->option('write');
        $force = (bool) $this->option('force');
        if ($force && ! $write) {
            $this->error('--force requires --write; the default mode never changes package files.');

            return self::FAILURE;
        }

        $this->info("Signature document data source scaffold: {$appKey} / {$sourceName}");
        $this->line("  source:      {$sourceKey}@{$sourceVersion}");
        $this->line("  subject:     {$subjectResourceKey} via {$subjectAnchor}");
        if ($needsSourceRefAnchor) {
            $this->line("  source ref:  {$sourceResourceKey} via {$sourceRefAnchor}");
        }
        $this->line("  bounds:      {$cardinality}, {$minItems}..{$maxItems}".($hasStableOrder ? ", stable {$stableSortKey}" : ''));
        $this->line($write ? '  mode:        write' : '  mode:        dry run (pass --write to create files)');

        return $this->writeFiles($files, $replacements, $write, $force)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function validResourceKey(string $appKey, string $resourceKey): bool
    {
        return $appKey !== '' && ResourceRef::hasCanonicalIdentity($appKey, $resourceKey);
    }

    private function resourceKeyOwner(string $resourceKey): string
    {
        $separator = strpos($resourceKey, '.');

        return $separator === false ? '' : substr($resourceKey, 0, $separator);
    }

    private function normalizedKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_]*)+$/D', $key) === 1;
    }

    private function positiveIntegerOption(string $option): ?int
    {
        $value = trim((string) $this->option($option));

        return preg_match('/\A[1-9]\d*\z/D', $value) === 1 ? (int) $value : null;
    }

    private function nonNegativeIntegerOption(string $option): ?int
    {
        $value = trim((string) $this->option($option));

        return preg_match('/\A(?:0|[1-9]\d*)\z/D', $value) === 1 ? (int) $value : null;
    }

    private function enumCase(string $value): string
    {
        return match ($value) {
            'string' => 'String',
            'text' => 'Text',
            'date' => 'Date',
            'datetime' => 'DateTime',
            'boolean' => 'Boolean',
            'integer' => 'Integer',
            'decimal' => 'Decimal',
            'money' => 'Money',
            'resource_ref' => 'ResourceRef',
            'public' => 'Public',
            'internal' => 'Internal',
            'confidential' => 'Confidential',
            'restricted' => 'Restricted',
            'plain' => 'Plain',
            'date_iso' => 'DateIso',
            'date_local' => 'DateLocal',
            'datetime_local' => 'DateTimeLocal',
            'boolean_yes_no' => 'BooleanYesNo',
            'money_with_currency' => 'MoneyWithCurrency',
            'resource_label' => 'ResourceLabel',
        };
    }

    private function lookupModeCase(string $value): string
    {
        return match ($value) {
            'direct_subject' => 'DirectSubject',
            'derived_ref' => 'DerivedRef',
            'explicit_source_ref' => 'ExplicitSourceRef',
            'owner_scoped_query' => 'OwnerScopedQuery',
        };
    }

    private function formatterMatchesType(string $type, string $formatter): bool
    {
        return match ($type) {
            'string', 'text' => $formatter === 'plain',
            'date' => in_array($formatter, ['date_iso', 'date_local'], true),
            'datetime' => $formatter === 'datetime_local',
            'boolean' => $formatter === 'boolean_yes_no',
            'integer' => $formatter === 'integer',
            'decimal' => $formatter === 'decimal',
            'money' => $formatter === 'money_with_currency',
            'resource_ref' => $formatter === 'resource_label',
            default => false,
        };
    }

    private function syntheticValue(string $type, string $appKey, string $sourceResourceKey, string $sourceName): string
    {
        return match ($type) {
            'string', 'text' => "'Example {$sourceName} value'",
            'date' => "'2026-01-01'",
            'datetime' => "'2026-01-01T09:00:00+00:00'",
            'boolean' => 'true',
            'integer' => '1',
            'decimal' => "'1.00'",
            'money' => "['amount' => '1.00', 'currency' => 'USD']",
            'resource_ref' => "[\n                        'app_key' => '{$appKey}',\n                        'resource_key' => '{$sourceResourceKey}',\n                        'resource_id' => '00000000-0000-4000-8000-000000000001',\n                        'display' => 'Example {$sourceName} record',\n                    ]",
        };
    }

    private function manyDescriptorField(string $appKey, string $sourceSlug, string $stableSortKey): string
    {
        return "\n                    new SignatureDocumentDataFieldDescriptor(\n                        key: '{$stableSortKey}',\n                        type: SignatureDocumentDataFieldType::Integer,\n                        required: true,\n                        classification: SignatureDataClassification::Internal,\n                        formatters: [SignatureDocumentDataFormatter::Integer],\n                        labelKey: '{$appKey}.signature_document_data.fields.{$sourceSlug}_{$stableSortKey}',\n                    ),";
    }

    /**
     * @param  array<string, string>  $files
     * @param  array<string, string>  $replacements
     */
    private function writeFiles(array $files, array $replacements, bool $write, bool $force): bool
    {
        $existing = array_filter($files, static fn (string $target): bool => is_file($target));
        if ($write && $existing !== [] && ! $force) {
            foreach ($existing as $target) {
                $this->error('  REFUSE  '.$this->relativePath($target).' (already exists; rerun with --write --force to overwrite every target)');
            }

            return false;
        }

        foreach ($files as $stub => $target) {
            if (! is_file($stub)) {
                $this->error("  Stub not found: {$stub}");

                return false;
            }
            $relative = $this->relativePath($target);
            $exists = is_file($target);
            if (! $write) {
                $this->line('  '.($exists ? 'WOULD REFUSE ' : 'WOULD CREATE ').$relative);

                continue;
            }

            $contents = (string) file_get_contents($stub);
            foreach ($replacements as $placeholder => $value) {
                $contents = str_replace($placeholder, $value, $contents);
            }
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            file_put_contents($target, $contents);
            $this->line('  '.($exists ? 'REWRITE ' : 'CREATE  ').$relative);
        }

        return true;
    }

    private function relativePath(string $absolute): string
    {
        return str_replace(base_path().'/', '', $absolute);
    }
}
