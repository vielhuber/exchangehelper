<?php
declare(strict_types=1);

use vielhuber\exchangehelper\exchangehelper;

class Test extends \PHPUnit\Framework\TestCase
{
    private ?exchangehelper $exchange = null;
    private ?string $contact_id = null;
    private ?string $event_id = null;
    private ?string $todo_list_id = null;
    private ?string $todo_task_id = null;

    protected function tearDown(): void
    {
        if ($this->exchange === null) {
            return;
        }
        if ($this->todo_task_id !== null && $this->todo_list_id !== null) {
            try {
                $this->exchange->removeTodoTask(list_id: $this->todo_list_id, id: $this->todo_task_id);
            } catch (Throwable) {
            }
        }
        if ($this->event_id !== null) {
            try {
                $this->exchange->removeCalendarEvent(id: $this->event_id);
            } catch (Throwable) {
            }
        }
        if ($this->contact_id !== null) {
            try {
                $this->exchange->removeContact(id: $this->contact_id);
            } catch (Throwable) {
            }
        }
    }

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

    public function test__live_exchange_crud_only_touches_test_entries(): void
    {
        foreach (['EXCHANGEHELPER_GRAPH_TENANT_ID', 'EXCHANGEHELPER_GRAPH_CLIENT_ID', 'EXCHANGEHELPER_GRAPH_CLIENT_SECRET', 'EXCHANGEHELPER_GRAPH_USER_ID'] as $key) {
            if ((getenv($key) ?: '') === '') {
                $this->markTestSkipped('Missing ' . $key . ' for live Microsoft Graph test.');
            }
        }

        $this->exchange = exchangehelper::create();
        $suffix = uniqid();

        $contacts = $this->exchange->getContacts(limit: 1);
        $this->assertIsArray($contacts);
        $contact = $this->exchange->addContact([
            'display_name' => 'exchangehelper integration contact ' . $suffix,
            'first_name' => 'exchangehelper',
            'last_name' => 'integration ' . $suffix,
            'emails' => ['exchangehelper-' . $suffix . '@example.invalid'],
            'company_name' => 'exchangehelper integration'
        ]);
        $this->contact_id = $contact['id'];
        $loaded_contact = $this->exchange->getContact(id: $this->contact_id);
        $this->assertSame($contact['display_name'], $loaded_contact['display_name']);
        $updated_contact = $this->exchange->updateContact(id: $this->contact_id, data: [
            'job_title' => 'updated by exchangehelper integration test'
        ]);
        $this->assertSame('updated by exchangehelper integration test', $updated_contact['job_title']);

        $events = $this->exchange->getCalendarEvents(
            start: gmdate('Y-m-d\T00:00:00\Z'),
            end: gmdate('Y-m-d\T23:59:59\Z', strtotime('+14 days')),
            limit: 1
        );
        $this->assertIsArray($events);
        $event = $this->exchange->addCalendarEvent([
            'subject' => 'exchangehelper integration event ' . $suffix,
            'start' => gmdate('Y-m-d\TH:i:s', strtotime('+7 days 10:00')),
            'end' => gmdate('Y-m-d\TH:i:s', strtotime('+7 days 10:15')),
            'timezone' => 'UTC',
            'location' => 'exchangehelper integration'
        ]);
        $this->event_id = $event['id'];
        $this->assertSame('exchangehelper integration event ' . $suffix, $event['subject']);
        $updated_event = $this->exchange->updateCalendarEvent(id: $this->event_id, data: [
            'location' => 'exchangehelper integration updated'
        ]);
        $this->assertSame('exchangehelper integration updated', $updated_event['location']);

        $lists = $this->exchange->getTodoLists();
        $this->assertIsArray($lists);
        if ($lists === []) {
            $this->markTestSkipped('No Microsoft To Do list exists for this user.');
        }
        $this->todo_list_id = (string) $lists[0]['id'];
        $tasks = $this->exchange->getTodoTasks(list_id: $this->todo_list_id);
        $this->assertIsArray($tasks);
        $task = $this->exchange->addTodoTask(list_id: $this->todo_list_id, data: [
            'title' => 'exchangehelper integration task ' . $suffix,
            'body' => 'created by exchangehelper integration test',
            'due' => gmdate('Y-m-d\TH:i:s', strtotime('+7 days')),
            'timezone' => 'UTC'
        ]);
        $this->todo_task_id = $task['id'];
        $this->assertSame('exchangehelper integration task ' . $suffix, $task['title']);
        $updated_task = $this->exchange->updateTodoTask(list_id: $this->todo_list_id, id: $this->todo_task_id, data: [
            'status' => 'completed'
        ]);
        $this->assertSame('completed', $updated_task['status']);

        $this->exchange->removeTodoTask(list_id: $this->todo_list_id, id: $this->todo_task_id);
        $this->todo_task_id = null;
        $this->exchange->removeCalendarEvent(id: $this->event_id);
        $this->event_id = null;
        $this->exchange->removeContact(id: $this->contact_id);
        $this->contact_id = null;
    }
}
