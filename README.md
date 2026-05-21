[![build status](https://github.com/vielhuber/exchangehelper/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/exchangehelper/actions)
[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/exchangehelper)](https://github.com/vielhuber/exchangehelper/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/exchangehelper)](https://github.com/vielhuber/exchangehelper/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/exchangehelper)](https://github.com/vielhuber/exchangehelper/commits)
[![PHP Version Support](https://img.shields.io/packagist/php-v/vielhuber/exchangehelper)](https://packagist.org/packages/vielhuber/exchangehelper)
[![Packagist Downloads](https://img.shields.io/packagist/dt/vielhuber/exchangehelper)](https://packagist.org/packages/vielhuber/exchangehelper)

# 📇 exchangehelper 📇

exchangehelper is a helper for Exchange, Outlook and Microsoft 365.

it focuses on contacts, calendar events and Microsoft To Do lists/tasks. mail stays outside this package because it is already covered by mailhelper.

## installation

install once with [composer](https://getcomposer.org/):

```
composer require vielhuber/exchangehelper
```

then add this to your files:

```php
require __DIR__ . '/vendor/autoload.php';
use vielhuber\exchangehelper\exchangehelper;
```

## graph setup

exchangehelper always reads Microsoft credentials from the `.env` in your project root. do not pass secrets in php.

create or extend your existing project `.env`:

delegated access with a refresh token:

```dotenv
EXCHANGEHELPER_GRAPH_TENANT_ID=common
EXCHANGEHELPER_GRAPH_CLIENT_ID=00000000-0000-0000-0000-000000000000
EXCHANGEHELPER_GRAPH_CLIENT_SECRET=secret
EXCHANGEHELPER_GRAPH_REFRESH_TOKEN=refresh-token
EXCHANGEHELPER_GRAPH_USER_ID=me
```

or a ready-to-use access token:

```dotenv
EXCHANGEHELPER_GRAPH_ACCESS_TOKEN=eyJ...
EXCHANGEHELPER_GRAPH_USER_ID=me
```

with application permissions, set `EXCHANGEHELPER_GRAPH_USER_ID` to the target mailbox user principal name:

```dotenv
EXCHANGEHELPER_GRAPH_TENANT_ID=contoso.onmicrosoft.com
EXCHANGEHELPER_GRAPH_CLIENT_ID=00000000-0000-0000-0000-000000000000
EXCHANGEHELPER_GRAPH_CLIENT_SECRET=secret
EXCHANGEHELPER_GRAPH_USER_ID=user@contoso.com
```

```php
$exchange = new exchangehelper();
```

## usage

### contacts

```php
$contacts = $exchange->getContacts(query: 'David', limit: 10);
$contact = $exchange->getContact(id: $contacts[0]['id']);

$created = $exchange->addContact([
    'display_name' => 'Ada Lovelace',
    'emails' => ['ada@example.com'],
    'phones' => [
        'mobile' => '+491701234567'
    ],
    'company_name' => 'Analytical Engines Ltd.'
]);

$updated = $exchange->updateContact(id: $created['id'], data: [
    'job_title' => 'Mathematician'
]);

$exchange->removeContact(id: $created['id']);
```

### calendar

```php
$events = $exchange->getCalendarEvents(
    start: '2026-05-01T00:00:00Z',
    end: '2026-05-31T23:59:59Z',
    limit: 50
);

$event = $exchange->addCalendarEvent([
    'subject' => 'Project sync',
    'start' => '2026-05-21T10:00:00',
    'end' => '2026-05-21T10:30:00',
    'timezone' => 'Europe/Berlin',
    'location' => 'Teams',
    'attendees' => ['ada@example.com']
]);

$exchange->removeCalendarEvent(id: $event['id']);
```

### to do

```php
$lists = $exchange->getTodoLists();
$tasks = $exchange->getTodoTasks(list_id: $lists[0]['id']);

$task = $exchange->addTodoTask(list_id: $lists[0]['id'], data: [
    'title' => 'Prepare meeting',
    'body' => 'Collect notes',
    'due' => '2026-05-21T18:00:00',
    'timezone' => 'Europe/Berlin'
]);

$exchange->updateTodoTask(list_id: $lists[0]['id'], id: $task['id'], data: [
    'status' => 'completed'
]);
```

## mcp server

exchangehelper ships as a standalone [mcp](https://modelcontextprotocol.io/) server for ai-agent workflows.

```
# run this from your project root where .env lives
vendor/bin/mcp-server.php
```

the server speaks both stdio (CLI invocation) and HTTP via [simplemcp](https://github.com/vielhuber/simplemcp). `auth: 'static'` mode expects the bearer token in `MCP_TOKEN` from your project `.env`.

available tools:

- `contacts_search(query?, limit?)`
- `contacts_get(id)`
- `calendar_list_events(start?, end?, limit?)`
- `todo_list_lists()`
- `todo_list_tasks(list_id?)`
- `todo_create_task(list_id, title, body?, due?)`
