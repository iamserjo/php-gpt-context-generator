<?php

namespace Iamserjo\PhpGptContextGenerator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Arr;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\select;

class GptContextGeneratorCommand extends Command
{
    protected $signature = 'xdev:gpt-project-context-generator';

    protected $description = 'Helps to create prompt context of your project according to chosen tables and files';

    protected string $suggestsFile = '.suggests.txt';

    public function handle()
    {
        // Load existing setups from suggests file
        $existingSetups = $this->loadSetups();

        // Ask user to select existing setup or create new
        $setupChoices = array_merge(
            ['<New Setup>'],
            array_map(fn($setup) => $setup['name'] ?? '', $existingSetups),
        );

        $chosenSetup = select(
            label: 'Choose a previously saved setup or create a new one:',
            options: $setupChoices
        );

        $isNewSetup = ($chosenSetup === '<New Setup>');
        $loadedSetup = null;

        if (!$isNewSetup) {
            // Load the chosen setup data
            $loadedSetup = Arr::first($existingSetups, fn($s) => $s['name'] === $chosenSetup);
        }

        // Tables to ignore
        $ignoreTables = [
            'cache',
            'cache_locks',
            'failed_jobs',
            'job_batches',
            'jobs',
            'migrations',
            'password_reset_tokens',
            'telescope_entries',
            'telescope_entries_tags',
            'telescope_monitoring',
        ];

        // Get all tables in the database
        $allTables = $this->getAllTables();

        // Exclude ignoreTables from the list
        $tablesToSelectFrom = array_diff($allTables, $ignoreTables);

        // Re-index the array to have table names as keys and values
        $tablesToSelectFrom = array_combine($tablesToSelectFrom, $tablesToSelectFrom);

        // If we loaded an existing setup, pre-select its tables
        $preselectedTables = $loadedSetup['tables'] ?? [];
        $selectedTables = multiselect(
            label: 'Select tables to include (type to search)',
            options: $tablesToSelectFrom,
            default: $preselectedTables
        );

        if (empty($selectedTables)) {
            $this->info('No tables selected.');
            return;
        }

        // Generate schema dump
        $schemaDump = $this->generateSchemaDump($selectedTables);

        // Get all PHP files in included directories, excluding certain directories
        [$allPhpFiles, $excludedDirectories, $includedDirectories] = $this->getAllPhpFiles();

        // Start with already selected files if we have a loaded setup
        $selectedFiles = $loadedSetup['files'] ?? [];

        // Exclude selected files from suggestions
        $filteredFiles = array_diff($allPhpFiles, $selectedFiles);
        $this->printFilesAsTree($selectedFiles);
        // Keep prompting the user for files until they press Enter
        do {
            $fileInput = suggest(
                label: 'Type part of the file name to include (or press Enter to finish)',
                options: function ($input) use (&$filteredFiles) {
                    $input = strtolower($input);
                    $matches = array_filter($filteredFiles, fn($file) => stripos(strtolower($file), $input) !== false);
                    return array_values($matches);
                }
            );

            if ($fileInput) {
                if (in_array($fileInput, $filteredFiles)) {
                    if (!in_array($fileInput, $selectedFiles)) {
                        $selectedFiles[] = $fileInput;
                        $this->info("Selected file: $fileInput");

                        // Update filtered files to exclude newly selected file
                        $filteredFiles = array_diff($filteredFiles, [$fileInput]);

                        // Show current selected files as a tree
                        $this->info("Current Selected Files Tree:");
                        $this->printFilesAsTree($selectedFiles);
                    } else {
                        $this->warn("File already selected: $fileInput");
                    }
                } else {
                    $this->warn("Invalid file selected.");
                }
            }
        } while ($fileInput);

        // Append selected files content to gpt_setup.txt
        $outputFile = 'gpt_setup.txt';
        file_put_contents($outputFile, $schemaDump);

        foreach ($selectedFiles as $relativePath) {
            $absolutePath = ($relativePath);

            if (!file_exists($absolutePath)) {
                $this->warn("File not found: $relativePath");
                continue;
            }

            $content = file_get_contents($absolutePath);
            $content = "File $absolutePath\n" . $content;
            file_put_contents($outputFile, "\n" . $content, FILE_APPEND);
            $filesize = filesize($absolutePath);
            if ($filesize > 13000) {
                continue;
            }
            $this->info("Appended file: {$filesize} $relativePath");
        }

        file_put_contents($outputFile, "\n" . $this->getDefaultPrompt(), FILE_APPEND);

        // Generate a name for the setup based on selected tables and files
        $setupName = $this->generateSetupName($selectedTables, $selectedFiles);

        // Save the new or updated setup
        $this->saveSetup($setupName, $selectedTables, $selectedFiles, $existingSetups);

        $this->info("Setup '{$setupName}' saved successfully in {$this->suggestsFile}.");
        $this->info('gpt_setup.txt has been generated successfully.');
    }

    protected function getAllTables()
    {
        $tables = DB::select('SHOW TABLES');

        return array_map(fn($table) => array_values((array)$table)[0], $tables);
    }

    protected function removeComments($sql)
    {
        // Remove /* ... */ comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        // Remove -- ... comments
        $sql = preg_replace('/--.*\n/', '', $sql);
        // Remove # ... comments
        $sql = preg_replace('/#.*\n/', '', $sql);

        return $sql;
    }

    protected function getDefaultPrompt()
    {
        return "
            - Behave as a professional programmer and person who is a database architect engineer.\n
            - Use db structure and project files to answer questions.\n
            - If you suggest a migration use anonymous classes instead of named ones\n
            ";
    }

    protected function generateSchemaDump(array $selectedTables): string
    {
        $schemaDump = '';
        foreach ($selectedTables as $table) {
            $this->info("Processing table: $table");

            $createTable = DB::select("SHOW CREATE TABLE `$table`");

            if (empty($createTable)) {
                $this->warn("No CREATE TABLE statement found for table: $table");
                continue;
            }

            $createTableSql = $createTable[0]->{'Create Table'};

            // Remove comments and auto-increment values
            $createTableSql = $this->removeComments($createTableSql);
            $createTableSql = preg_replace('/AUTO_INCREMENT=\d+ /', '', $createTableSql);

            // Ensure it ends with a semicolon
            if (substr(trim($createTableSql), -1) !== ';') {
                $createTableSql .= ';';
            }

            $schemaDump .= $createTableSql . "\n\n";
        }
        return $schemaDump;
    }

    protected function getAllPhpFiles(): array
    {
        $excludedDirectories = [
            'vendor',
            'node_modules',
            'bootstrap',
            'docker',
            'lang',
            'storage',
            'mysql',
        ];

        $includedDirectories = [
            'app',
            'routes',
            'config',
            'resources',
            'tests',
            'database',
        ];

        $this->warn('Searching for files in the project');
        $allFiles = File::allFiles(\App\Console\Commands\base_path());

        $filteredFiles = [];

        foreach ($allFiles as $file) {
            $relativePath = $file->getRelativePathname();

            // Exclude files in excluded directories
            if ($this->isExcluded($relativePath, $excludedDirectories)) {
                continue;
            }

            // Only include files in included directories and with .php extension
            if ($this->isIncluded($relativePath, $includedDirectories)
                && pathinfo($relativePath, PATHINFO_EXTENSION) === 'php') {
                $filteredFiles[] = $relativePath;
            }
        }

        return [$filteredFiles, $excludedDirectories, $includedDirectories];
    }

    protected function isExcluded(string $path, array $excludedDirectories): bool
    {
        foreach ($excludedDirectories as $excludedDir) {
            if (stripos($path, $excludedDir . DIRECTORY_SEPARATOR) === 0 || stripos($path, $excludedDir) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function isIncluded(string $path, array $includedDirectories): bool
    {
        foreach ($includedDirectories as $includedDir) {
            if (stripos($path, $includedDir . DIRECTORY_SEPARATOR) === 0) {
                return true;
            }
        }
        return false;
    }

    protected function loadSetups(): array
    {
        if (!file_exists($this->suggestsFile)) {
            return [];
        }

        $json = file_get_contents($this->suggestsFile);
        $data = json_decode($json, true);
        return $data ?: [];
    }

    protected function saveSetup(string $name, array $tables, array $files, array $existingSetups)
    {
        // Check if a setup with the same name exists, remove it if yes
        $existingSetups = array_filter($existingSetups, fn($s) => $s['name'] !== $name);

        $existingSetups[] = [
            'name' => $name,
            'tables' => $tables,
            'files' => $files
        ];

        file_put_contents($this->suggestsFile, json_encode(array_values($existingSetups), JSON_PRETTY_PRINT));
    }

    protected function generateSetupName(array $tables, array $files): string
    {
        $tablesPart = implode(', ', $tables);
        $filesPart = implode(', ', array_map(function($f) {
            return basename($f);
        }, $files));

        $name = trim($tablesPart . '_' . $filesPart, '_');
        if (empty($name)) {
            $name = 'setup_' . date('Ymd_His');
        }
        return $name;
    }

    protected function printFilesAsTree(array $files)
    {
        // Sort files to get a nicer tree structure
        sort($files);

        // Build a nested tree structure
        $tree = [];
        foreach ($files as $file) {
            $parts = explode(DIRECTORY_SEPARATOR, $file);
            $current = &$tree;
            foreach ($parts as $part) {
                if (!isset($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }

        // Recursive function to print tree
        $this->printTree($tree);
    }

    protected function printTree(array $tree, $prefix = '')
    {
        $entries = array_keys($tree);
        $count = count($entries);
        foreach ($entries as $index => $entry) {
            $isLast = ($index === $count - 1);
            $connector = $isLast ? '└── ' : '├── ';
            $this->info($prefix . $connector . $entry);

            if (!empty($tree[$entry])) {
                $newPrefix = $prefix . ($isLast ? '    ' : '│   ');
                $this->printTree($tree[$entry], $newPrefix);
            }
        }
    }
}
