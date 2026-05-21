<?php
declare(strict_types=1);

use vielhuber\exchangehelper\exchangehelper;

class Test extends \PHPUnit\Framework\TestCase
{
    public function test__normalize_graph_contact(): void
    {
        $contact = exchangehelper::normalizeGraphContact([
            'id' => '1',
            'displayName' => 'Ada Lovelace',
            'givenName' => 'Ada',
            'surname' => 'Lovelace',
            'companyName' => 'Analytical Engines Ltd.',
            'jobTitle' => 'Mathematician',
            'emailAddresses' => [
                ['address' => 'ada@example.com', 'name' => 'Ada']
            ],
            'businessPhones' => ['+491111'],
            'homePhones' => ['+492222'],
            'mobilePhone' => '+493333',
            'businessHomePage' => 'https://example.com',
            'categories' => ['vip']
        ]);

        $this->assertSame('1', $contact['id']);
        $this->assertSame('Ada Lovelace', $contact['display_name']);
        $this->assertSame(['ada@example.com'], $contact['emails']);
        $this->assertSame(['+493333'], $contact['phones']['mobile']);
    }

    public function test__contact_query_matches_name_email_and_phone(): void
    {
        $contact = exchangehelper::normalizeGraphContact([
            'displayName' => 'Ada Lovelace',
            'emailAddresses' => [
                ['address' => 'ada@example.com']
            ],
            'mobilePhone' => '+493333'
        ]);

        $this->assertTrue(exchangehelper::contactMatchesQuery($contact, 'lovelace'));
        $this->assertTrue(exchangehelper::contactMatchesQuery($contact, 'ada@example.com'));
        $this->assertTrue(exchangehelper::contactMatchesQuery($contact, '+493333'));
        $this->assertFalse(exchangehelper::contactMatchesQuery($contact, 'grace'));
    }

    public function test__normalize_graph_event(): void
    {
        $event = exchangehelper::normalizeGraphEvent([
            'id' => 'event-1',
            'subject' => 'Project sync',
            'start' => ['dateTime' => '2026-05-21T10:00:00', 'timeZone' => 'Europe/Berlin'],
            'end' => ['dateTime' => '2026-05-21T10:30:00', 'timeZone' => 'Europe/Berlin'],
            'location' => ['displayName' => 'Teams'],
            'attendees' => [
                [
                    'emailAddress' => ['address' => 'ada@example.com', 'name' => 'Ada'],
                    'type' => 'required',
                    'status' => ['response' => 'accepted']
                ]
            ]
        ]);

        $this->assertSame('event-1', $event['id']);
        $this->assertSame('Project sync', $event['subject']);
        $this->assertSame('Europe/Berlin', $event['timezone']);
        $this->assertSame('ada@example.com', $event['attendees'][0]['email']);
    }

    public function test__normalize_todo_list_and_task(): void
    {
        $list = exchangehelper::normalizeGraphTodoList([
            'id' => 'list-1',
            'displayName' => 'Tasks',
            'wellknownListName' => 'defaultList'
        ]);
        $task = exchangehelper::normalizeGraphTodoTask([
            'id' => 'task-1',
            'title' => 'Prepare meeting',
            'status' => 'notStarted',
            'importance' => 'normal',
            'dueDateTime' => ['dateTime' => '2026-05-21T18:00:00', 'timeZone' => 'Europe/Berlin'],
            'body' => ['contentType' => 'text', 'content' => 'Collect notes']
        ], 'list-1');

        $this->assertSame('Tasks', $list['name']);
        $this->assertSame('defaultList', $list['wellknown_name']);
        $this->assertSame('task-1', $task['id']);
        $this->assertSame('list-1', $task['list_id']);
        $this->assertSame('Prepare meeting', $task['title']);
        $this->assertSame('Collect notes', $task['body']);
    }

    public function test__can_create_without_arguments(): void
    {
        $this->assertInstanceOf(exchangehelper::class, exchangehelper::create());
    }

    public function test__reads_env_from_project_root(): void
    {
        $previous_cwd = getcwd();
        $previous_user_id = getenv('EXCHANGEHELPER_GRAPH_USER_ID');
        putenv('EXCHANGEHELPER_GRAPH_USER_ID');
        unset($_ENV['EXCHANGEHELPER_GRAPH_USER_ID'], $_SERVER['EXCHANGEHELPER_GRAPH_USER_ID']);

        $directory = sys_get_temp_dir() . '/exchangehelper_env_' . uniqid();
        mkdir($directory);
        file_put_contents($directory . '/.env', "EXCHANGEHELPER_GRAPH_USER_ID=user@example.com\n");
        chdir($directory);

        try {
            $exchange = exchangehelper::create();
            $reflection = new ReflectionClass($exchange);
            $property = $reflection->getProperty('config');
            $config = $property->getValue($exchange);

            $this->assertSame('user@example.com', $config['user_id']);
        } finally {
            if (is_string($previous_cwd)) {
                chdir($previous_cwd);
            }
            @unlink($directory . '/.env');
            @rmdir($directory);
            if ($previous_user_id === false) {
                putenv('EXCHANGEHELPER_GRAPH_USER_ID');
                unset($_ENV['EXCHANGEHELPER_GRAPH_USER_ID'], $_SERVER['EXCHANGEHELPER_GRAPH_USER_ID']);
            } else {
                putenv('EXCHANGEHELPER_GRAPH_USER_ID=' . $previous_user_id);
                $_ENV['EXCHANGEHELPER_GRAPH_USER_ID'] = $previous_user_id;
                $_SERVER['EXCHANGEHELPER_GRAPH_USER_ID'] = $previous_user_id;
            }
        }
    }
}
