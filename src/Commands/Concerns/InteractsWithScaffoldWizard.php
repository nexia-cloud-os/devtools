<?php

declare(strict_types=1);

namespace Nexia\Devtools\Commands\Concerns;

/**
 * Shared interactive input flow for source scaffolding commands.
 *
 * A command enters wizard mode only when an interactive caller omits a
 * required value. Existing fully-specified CLI and automation calls retain
 * their non-interactive behaviour; wizard mode then lets a human review and
 * adjust the derived defaults before the command writes files.
 */
trait InteractsWithScaffoldWizard
{
    private bool $scaffoldWizardActive = false;

    protected function scaffoldRequiredArgument(
        string $argument,
        string $question,
        string $nonInteractiveMessage,
    ): ?string {
        $value = trim((string) $this->argument($argument));

        if ($value !== '') {
            return $value;
        }

        if (! $this->input->isInteractive()) {
            $this->error($nonInteractiveMessage);

            return null;
        }

        $this->scaffoldWizardActive = true;
        $value = trim((string) $this->ask($question));

        if ($value === '') {
            $this->error("{$question} is required.");

            return null;
        }

        return $value;
    }

    protected function scaffoldRequiredOption(
        string $option,
        string $question,
        string $nonInteractiveMessage,
    ): ?string {
        $value = trim((string) $this->option($option));

        if ($value !== '') {
            return $value;
        }

        if (! $this->input->isInteractive()) {
            $this->error($nonInteractiveMessage);

            return null;
        }

        $this->scaffoldWizardActive = true;
        $value = trim((string) $this->ask($question));

        if ($value === '') {
            $this->error(ucfirst(str_replace('-', ' ', $option)).' is required.');

            return null;
        }

        return $value;
    }

    protected function beginScaffoldWizard(): void
    {
        if ($this->input->isInteractive()) {
            $this->scaffoldWizardActive = true;
        }
    }

    protected function scaffoldWizardIsActive(): bool
    {
        return $this->scaffoldWizardActive;
    }

    protected function scaffoldText(string $question, string $value, string $default = ''): string
    {
        if (! $this->scaffoldWizardActive) {
            return $value !== '' ? $value : $default;
        }

        return trim((string) $this->ask($question, $value !== '' ? $value : $default));
    }

    /**
     * @param  list<string>  $choices
     */
    protected function scaffoldChoice(string $question, array $choices, string $value, string $default): string
    {
        if (! $this->scaffoldWizardActive) {
            return $value !== '' ? $value : $default;
        }

        $selected = $value !== '' ? $value : $default;
        $selectedIndex = array_search($selected, $choices, true);
        if ($selectedIndex === false) {
            $selectedIndex = array_search($default, $choices, true);
        }

        return (string) $this->choice($question, $choices, $selectedIndex);
    }

    protected function confirmScaffoldPlan(string $question, bool $dryRun): bool
    {
        if (! $this->scaffoldWizardActive || $dryRun) {
            return true;
        }

        if ($this->confirm($question, false)) {
            return true;
        }

        $this->info('Scaffold cancelled. No files were written.');

        return false;
    }
}
