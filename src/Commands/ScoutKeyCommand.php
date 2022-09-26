<?php

namespace OpenSoutheners\LaravelScoutAdvancedMeilisearch\Commands;

use MeiliSearch\Exceptions\TimeOutException;

class ScoutKeyCommand extends MeilisearchCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:key {key? : UUID or key value to perform update or delete}
                            {--actions : Comma separated list of API actions to be allowed for this key (only create)}
                            {--indexes : Comma separated list of indexes the key is authorized to act on (only create)}
                            {--expires : Date and time when the key will expire (only create)}
                            {--name : A human-readable name for the key}
                            {--description : An optional description for the key}
                            {--uid : A UUID to identify the API key. If not specified, it is generated by Meilisearch}
                            {--create : Trigger an key creation}
                            {--update : Trigger an key modification}
                            {--delete : Trigger an key deletion}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create, update or delete API keys (Meilisearch only)';

    /**
     * @see https://docs.meilisearch.com/reference/api/keys.html#key-object
     *
     * @var array<string>
     */
    protected $actions = [
        'search', 'documents.add', 'documents.get', 'documents.delete',
        'indexes.create', 'indexes.get', 'indexes.update', 'indexes.delete',
        'tasks.get', 'settings.get', 'settings.update', 'stats.get', 'dumps.create',
        'version', 'keys.get', 'keys.create', 'keys.update', 'keys.delete',
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($exitCode = $this->checkUsingMeilisearch()) {
            return $exitCode;
        }

        if (! $this->argument('key') && ! $this->option('create') && ! $this->option('update') && ! $this->option('delete')) {
            $this->error('You need to specify an action or pass a key for this command.');

            return 2;
        }

        if (($this->option('update') || $this->option('delete')) && ! $this->argument('key')) {
            $this->error('You need to pass a key value or UUID to be able to perform this action.');

            return 3;
        }

        return $this->handleAction();
    }

    /**
     * Handle action with given key.
     *
     * @return int
     */
    protected function handleAction()
    {
        $action = 'no';
        $task = null;

        switch (true) {
            case $this->option('update'):
                $action = 'deletion';
                $this->handleDeletion();
                break;

            case $this->option('update'):
                $action = 'modification';
                $task = $this->handleModification();
                break;

            default:
            case $this->option('create'):
                $action = 'creation';
                $task = $this->handleCreation();
                break;
        }

        if ($action !== 'deletion' && $task && ! $task->getUid()) {
            $this->error('Something went wrong...');

            return 4;
        }

        $this->info(sprintf('Key %s action performed successfully!', $action));

        return 0;
    }

    /**
     * Handle API key creation.
     *
     * @return \MeiliSearch\Endpoints\Keys
     */
    protected function handleCreation()
    {
        return $this->searchEngine->createKey(
            $this->getKeyDataFromOptions(null, true)
        );
    }

    /**
     * Handle API key modification.
     *
     * @return \MeiliSearch\Endpoints\Keys
     */
    protected function handleModification()
    {
        $key = $this->argument('key');

        $options = $this->getKeyDataFromOptions($key);

        return $this->searchEngine->updateKey($key, $options);
    }

    /**
     * Handle API key deletion.
     *
     * @return array
     */
    protected function handleDeletion()
    {
        return $this->searchEngine->deleteKey($this->argument('key'));
    }

    /**
     * Get filtered command options as API key data.
     *
     * @param  string|null  $key
     * @return array
     */
    protected function getKeyDataFromOptions($key = null)
    {
        $originalOptions = array_filter($this->options());
        /** @var \MeiliSearch\Endpoints\Keys|array $keyData */
        $keyData = $key ? $this->searchEngine->getKey($key) : [];
        $options = [];

        if (is_null($key)) {
            $this->getDataOptionsFromKeyCreate($originalOptions, $options);
        }

        $this->getDataOptionsFromKeyUpdate($originalOptions, $keyData, $options);

        return $options;
    }

    /**
     * Get creation data options from key data.
     *
     * @param  array  $originalOptions
     * @param  \MeiliSearch\Endpoints\Keys|array  $keyData
     * @param  array  $options
     * @return void
     */
    protected function getDataOptionsFromKeyCreate($originalOptions, array &$options)
    {
        $options['actions'] = explode(',', $this->askWithCompletionList(
            'Comma separated list of API actions to be allowed for this key',
            $this->actions,
            implode(',', $originalOptions['actions'] ?? ['*'])
        ));

        $options['indexes'] = explode(',', $this->askWithCompletionList(
            'Comma separated list of indexes the key is authorized to act on',
            array_column($this->searchEngine->getAllRawIndexes()['results'] ?? [], 'uid'),
            implode(',', $originalOptions['indexes'] ?? ['*'])
        ));

        $options['expiresAt'] = $this->ask(
            'Date and time when the key will expire (e.g. 1 hour, 6 months, 10 years...)',
            $originalOptions['expires'] ?? null
        );

        if (! is_null($options['expiresAt'])) {
            $options['expiresAt'] = now()->add($options['expiresAt'])->toIso8601ZuluString();
        }
    }

    /**
     * Get creation/modification data options from key data.
     *
     * @param  array  $originalOptions
     * @param  \MeiliSearch\Endpoints\Keys|array  $keyData
     * @param  array  $options
     * @return void
     */
    protected function getDataOptionsFromKeyUpdate($originalOptions, $keyData, array &$options)
    {
        $options['name'] = $this->ask(
            'A human-readable name for the key',
            $originalOptions['name'] ?? optional($keyData)->getName() ?? ''
        );

        $options['description'] = $this->ask(
            'An optional description for the key',
            $originalOptions['description'] ?? optional($keyData)->getDescription() ?? null
        );
    }
}
