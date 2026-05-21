<?php
declare(strict_types=1);

namespace vielhuber\exchangehelper;

use RuntimeException;
use vielhuber\simplemcp\Attributes\McpTool;

final class exchangehelper
{
    private const DEFAULT_GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';

    private array $config;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->loadEnvFile();
        $this->config = $this->envConfig();
    }

    public static function create(): self
    {
        return new self();
    }

    public function getContacts(?string $query = null, int $limit = 50): array
    {
        $limit = $this->normalizeLimit($limit);
        $contacts = $this->graphCollection(
            $this->userPath('/contacts'),
            [
                '$top' => min(999, $limit),
                '$select' => 'id,displayName,givenName,surname,companyName,jobTitle,emailAddresses,businessPhones,homePhones,mobilePhone,businessHomePage,categories'
            ]
        );
        $contacts = array_map(fn(array $contact): array => self::normalizeGraphContact($contact), $contacts);
        return array_slice($this->filterContacts($contacts, $query), 0, $limit);
    }

    public function getContact(string $id): array
    {
        return self::normalizeGraphContact($this->graphRequest('GET', $this->userPath('/contacts/' . rawurlencode($id))));
    }

    public function addContact(array $data): array
    {
        return self::normalizeGraphContact($this->graphRequest('POST', $this->userPath('/contacts'), $this->buildGraphContact($data)));
    }

    public function updateContact(string $id, array $data): array
    {
        return self::normalizeGraphContact($this->graphRequest('PATCH', $this->userPath('/contacts/' . rawurlencode($id)), $this->buildGraphContact($data)));
    }

    public function removeContact(string $id): void
    {
        $this->graphRequest('DELETE', $this->userPath('/contacts/' . rawurlencode($id)));
    }

    public function getCalendarEvents(?string $start = null, ?string $end = null, int $limit = 50): array
    {
        $limit = $this->normalizeLimit($limit);
        $path = $this->userPath('/calendar/events');
        $query = [
            '$top' => min(999, $limit),
            '$orderby' => 'start/dateTime'
        ];

        if ($start !== null && $end !== null) {
            $path = $this->userPath('/calendarView');
            $query['startDateTime'] = $start;
            $query['endDateTime'] = $end;
        }

        $events = $this->graphCollection($path, $query);
        $events = array_map(fn(array $event): array => self::normalizeGraphEvent($event), $events);
        return array_slice($events, 0, $limit);
    }

    public function addCalendarEvent(array $data): array
    {
        return self::normalizeGraphEvent($this->graphRequest('POST', $this->userPath('/calendar/events'), $this->buildGraphEvent($data)));
    }

    public function updateCalendarEvent(string $id, array $data): array
    {
        return self::normalizeGraphEvent($this->graphRequest('PATCH', $this->userPath('/events/' . rawurlencode($id)), $this->buildGraphEvent($data)));
    }

    public function removeCalendarEvent(string $id): void
    {
        $this->graphRequest('DELETE', $this->userPath('/events/' . rawurlencode($id)));
    }

    public function getTodoLists(): array
    {
        return array_map(
            fn(array $list): array => self::normalizeGraphTodoList($list),
            $this->graphCollection($this->userPath('/todo/lists'))
        );
    }

    public function getTodoTasks(?string $list_id = null): array
    {
        if ($list_id !== null && $list_id !== '') {
            return array_map(
                fn(array $task): array => self::normalizeGraphTodoTask($task, $list_id),
                $this->graphCollection($this->userPath('/todo/lists/' . rawurlencode($list_id) . '/tasks'))
            );
        }

        $tasks = [];
        foreach ($this->getTodoLists() as $list) {
            foreach ($this->getTodoTasks((string) $list['id']) as $task) {
                $tasks[] = $task;
            }
        }
        return $tasks;
    }

    public function addTodoTask(string $list_id, array $data): array
    {
        return self::normalizeGraphTodoTask(
            $this->graphRequest('POST', $this->userPath('/todo/lists/' . rawurlencode($list_id) . '/tasks'), $this->buildGraphTodoTask($data)),
            $list_id
        );
    }

    public function updateTodoTask(string $list_id, string $id, array $data): array
    {
        return self::normalizeGraphTodoTask(
            $this->graphRequest('PATCH', $this->userPath('/todo/lists/' . rawurlencode($list_id) . '/tasks/' . rawurlencode($id)), $this->buildGraphTodoTask($data)),
            $list_id
        );
    }

    public function removeTodoTask(string $list_id, string $id): void
    {
        $this->graphRequest('DELETE', $this->userPath('/todo/lists/' . rawurlencode($list_id) . '/tasks/' . rawurlencode($id)));
    }

    #[McpTool(name: 'contacts_search')]
    public function contactsSearch(?string $query = null, ?int $limit = null): array
    {
        return ['items' => $this->getContacts(query: $query, limit: $limit ?? 10)];
    }

    #[McpTool(name: 'contacts_get')]
    public function contactsGet(string $id): array
    {
        return $this->getContact(id: $id);
    }

    #[McpTool(name: 'calendar_list_events')]
    public function calendarListEvents(?string $start = null, ?string $end = null, ?int $limit = null): array
    {
        return ['items' => $this->getCalendarEvents(start: $start, end: $end, limit: $limit ?? 25)];
    }

    #[McpTool(name: 'todo_list_lists')]
    public function todoListLists(): array
    {
        return ['items' => $this->getTodoLists()];
    }

    #[McpTool(name: 'todo_list_tasks')]
    public function todoListTasks(?string $list_id = null): array
    {
        return ['items' => $this->getTodoTasks(list_id: $list_id)];
    }

    #[McpTool(name: 'todo_create_task')]
    public function todoCreateTask(string $list_id, string $title, ?string $body = null, ?string $due = null): array
    {
        return $this->addTodoTask(
            list_id: $list_id,
            data: [
                'title' => $title,
                'body' => $body,
                'due' => $due
            ]
        );
    }

    public static function normalizeGraphContact(array $contact): array
    {
        return [
            'id' => (string) ($contact['id'] ?? ''),
            'display_name' => (string) ($contact['displayName'] ?? ''),
            'first_name' => (string) ($contact['givenName'] ?? ''),
            'last_name' => (string) ($contact['surname'] ?? ''),
            'company_name' => (string) ($contact['companyName'] ?? ''),
            'job_title' => (string) ($contact['jobTitle'] ?? ''),
            'emails' => self::normalizeEmailAddresses($contact['emailAddresses'] ?? []),
            'phones' => [
                'business' => self::stringList($contact['businessPhones'] ?? []),
                'home' => self::stringList($contact['homePhones'] ?? []),
                'mobile' => self::stringList([$contact['mobilePhone'] ?? null])
            ],
            'url' => (string) ($contact['businessHomePage'] ?? ''),
            'categories' => self::stringList($contact['categories'] ?? []),
            'raw' => $contact
        ];
    }

    public static function contactMatchesQuery(array $contact, ?string $query): bool
    {
        $query = mb_strtolower(trim((string) $query));
        if ($query === '') {
            return true;
        }
        $haystack = [
            $contact['display_name'] ?? '',
            $contact['first_name'] ?? '',
            $contact['last_name'] ?? '',
            $contact['company_name'] ?? '',
            $contact['job_title'] ?? '',
            $contact['url'] ?? '',
            implode(' ', $contact['emails'] ?? []),
            implode(' ', $contact['phones']['business'] ?? []),
            implode(' ', $contact['phones']['home'] ?? []),
            implode(' ', $contact['phones']['mobile'] ?? [])
        ];
        return str_contains(mb_strtolower(implode(' ', $haystack)), $query);
    }

    public static function normalizeGraphEvent(array $event): array
    {
        return [
            'id' => (string) ($event['id'] ?? ''),
            'subject' => (string) ($event['subject'] ?? ''),
            'start' => (string) ($event['start']['dateTime'] ?? ''),
            'end' => (string) ($event['end']['dateTime'] ?? ''),
            'timezone' => (string) ($event['start']['timeZone'] ?? $event['end']['timeZone'] ?? ''),
            'location' => (string) ($event['location']['displayName'] ?? ''),
            'attendees' => self::normalizeAttendees($event['attendees'] ?? []),
            'organizer' => (string) ($event['organizer']['emailAddress']['address'] ?? ''),
            'is_all_day' => (bool) ($event['isAllDay'] ?? false),
            'web_link' => (string) ($event['webLink'] ?? ''),
            'categories' => self::stringList($event['categories'] ?? []),
            'raw' => $event
        ];
    }

    public static function normalizeGraphTodoList(array $list): array
    {
        return [
            'id' => (string) ($list['id'] ?? ''),
            'name' => (string) ($list['displayName'] ?? ''),
            'wellknown_name' => (string) ($list['wellknownListName'] ?? ''),
            'raw' => $list
        ];
    }

    public static function normalizeGraphTodoTask(array $task, ?string $list_id = null): array
    {
        return [
            'id' => (string) ($task['id'] ?? ''),
            'list_id' => (string) ($list_id ?? ''),
            'title' => (string) ($task['title'] ?? ''),
            'status' => (string) ($task['status'] ?? ''),
            'importance' => (string) ($task['importance'] ?? ''),
            'due' => (string) ($task['dueDateTime']['dateTime'] ?? ''),
            'timezone' => (string) ($task['dueDateTime']['timeZone'] ?? ''),
            'body' => (string) ($task['body']['content'] ?? ''),
            'body_type' => (string) ($task['body']['contentType'] ?? ''),
            'completed' => (string) ($task['completedDateTime']['dateTime'] ?? ''),
            'raw' => $task
        ];
    }

    private function envConfig(): array
    {
        return [
            'graph_base_url' => getenv('EXCHANGEHELPER_GRAPH_BASE_URL') ?: self::DEFAULT_GRAPH_BASE_URL,
            'tenant_id' => getenv('EXCHANGEHELPER_GRAPH_TENANT_ID') ?: null,
            'client_id' => getenv('EXCHANGEHELPER_GRAPH_CLIENT_ID') ?: null,
            'client_secret' => getenv('EXCHANGEHELPER_GRAPH_CLIENT_SECRET') ?: null,
            'refresh_token' => getenv('EXCHANGEHELPER_GRAPH_REFRESH_TOKEN') ?: null,
            'access_token' => getenv('EXCHANGEHELPER_GRAPH_ACCESS_TOKEN') ?: null,
            'user_id' => getenv('EXCHANGEHELPER_GRAPH_USER_ID') ?: 'me'
        ];
    }

    private function loadEnvFile(): void
    {
        $env_path = $this->findEnvPath();
        if ($env_path === null) {
            return;
        }
        $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('exchangehelper: failed to read .env.');
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function findEnvPath(): ?string
    {
        $explicit_path = getenv('EXCHANGEHELPER_ENV_PATH');
        if (is_string($explicit_path) && $explicit_path !== '') {
            if (!is_file($explicit_path)) {
                throw new RuntimeException('exchangehelper: EXCHANGEHELPER_ENV_PATH does not point to a readable .env file.');
            }
            return $explicit_path;
        }

        $directories = [];
        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $directories[] = $cwd;
        }
        $script_filename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if (is_string($script_filename) && $script_filename !== '') {
            $directories[] = dirname($script_filename);
        }

        foreach (array_values(array_unique($directories)) as $directory) {
            $env_path = $this->findEnvPathInAncestors($directory);
            if ($env_path !== null) {
                return $env_path;
            }
        }
        return null;
    }

    private function findEnvPathInAncestors(string $directory): ?string
    {
        $directory = realpath($directory);
        if ($directory === false) {
            return null;
        }
        while (true) {
            $env_path = $directory . '/.env';
            if (is_file($env_path)) {
                return $env_path;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }
            $directory = $parent;
        }
    }

    private function filterContacts(array $contacts, ?string $query): array
    {
        return array_values(array_filter($contacts, fn(array $contact): bool => self::contactMatchesQuery($contact, $query)));
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(999, $limit));
    }

    private function graphCollection(string $path, array $query = []): array
    {
        $items = [];
        $next = $this->buildGraphUrl($path, $query);
        while ($next !== null) {
            $response = $this->graphRequest('GET', $next);
            foreach ($response['value'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $next = isset($response['@odata.nextLink']) && is_string($response['@odata.nextLink']) ? $response['@odata.nextLink'] : null;
        }
        return $items;
    }

    private function graphRequest(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = str_starts_with($path, 'https://') ? $path : $this->buildGraphUrl($path, $query);
        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Accept: application/json'
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('exchangehelper: failed to initialize curl.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('exchangehelper: graph request failed: ' . $error);
        }
        if ($status >= 400) {
            throw new RuntimeException('exchangehelper: graph request failed with HTTP ' . $status . ': ' . $response);
        }
        if ($response === '' || $status === 204) {
            return [];
        }

        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    private function buildGraphUrl(string $path, array $query = []): string
    {
        $base = rtrim((string) ($this->config['graph_base_url'] ?? self::DEFAULT_GRAPH_BASE_URL), '/');
        $url = $base . '/' . ltrim($path, '/');
        $query = array_filter($query, fn(mixed $value): bool => $value !== null && $value !== '');
        if ($query === []) {
            return $url;
        }
        return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function userPath(string $suffix): string
    {
        $user_id = (string) ($this->config['user_id'] ?? 'me');
        if ($user_id === '' || $user_id === 'me') {
            return '/me' . $suffix;
        }
        return '/users/' . rawurlencode($user_id) . $suffix;
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        if (($this->config['access_token'] ?? null) !== null && $this->config['access_token'] !== '') {
            $this->accessToken = (string) $this->config['access_token'];
            return $this->accessToken;
        }

        $tenant_id = (string) ($this->config['tenant_id'] ?? '');
        $client_id = (string) ($this->config['client_id'] ?? '');
        $client_secret = (string) ($this->config['client_secret'] ?? '');
        if ($tenant_id === '' || $client_id === '' || $client_secret === '') {
            throw new RuntimeException('exchangehelper: missing graph credentials in root .env.');
        }

        $payload = [
            'client_id' => $client_id,
            'client_secret' => $client_secret
        ];
        if (($this->config['refresh_token'] ?? null) !== null && $this->config['refresh_token'] !== '') {
            $payload['grant_type'] = 'refresh_token';
            $payload['refresh_token'] = (string) $this->config['refresh_token'];
            $payload['scope'] = 'offline_access Contacts.ReadWrite Calendars.ReadWrite Tasks.ReadWrite User.Read';
        } else {
            $payload['grant_type'] = 'client_credentials';
            $payload['scope'] = 'https://graph.microsoft.com/.default';
        }

        $curl = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenant_id) . '/oauth2/v2.0/token');
        if ($curl === false) {
            throw new RuntimeException('exchangehelper: failed to initialize curl.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 60
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('exchangehelper: token request failed: ' . $error);
        }
        if ($status >= 400) {
            throw new RuntimeException('exchangehelper: token request failed with HTTP ' . $status . ': ' . $response);
        }
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new RuntimeException('exchangehelper: token response did not contain access_token.');
        }
        $this->accessToken = $decoded['access_token'];
        return $this->accessToken;
    }

    private function buildGraphContact(array $data): array
    {
        $contact = [];
        foreach (
            [
                'display_name' => 'displayName',
                'first_name' => 'givenName',
                'last_name' => 'surname',
                'company_name' => 'companyName',
                'job_title' => 'jobTitle',
                'url' => 'businessHomePage'
            ]
            as $source => $target
        ) {
            if (array_key_exists($source, $data)) {
                $contact[$target] = $data[$source];
            }
        }
        if (array_key_exists('emails', $data)) {
            $contact['emailAddresses'] = array_map(
                fn(string $email): array => ['address' => $email, 'name' => $email],
                self::stringList($data['emails'])
            );
        }
        if (isset($data['phones']) && is_array($data['phones'])) {
            if (array_key_exists('business', $data['phones'])) {
                $contact['businessPhones'] = self::stringList($data['phones']['business']);
            }
            if (array_key_exists('home', $data['phones'])) {
                $contact['homePhones'] = self::stringList($data['phones']['home']);
            }
            if (array_key_exists('mobile', $data['phones'])) {
                $contact['mobilePhone'] = self::stringList($data['phones']['mobile'])[0] ?? '';
            }
        }
        if (array_key_exists('categories', $data)) {
            $contact['categories'] = self::stringList($data['categories']);
        }
        return $contact;
    }

    private function buildGraphEvent(array $data): array
    {
        $timezone = (string) ($data['timezone'] ?? 'UTC');
        $event = [];
        if (array_key_exists('subject', $data)) {
            $event['subject'] = $data['subject'];
        }
        if (array_key_exists('start', $data)) {
            $event['start'] = ['dateTime' => $data['start'], 'timeZone' => $timezone];
        }
        if (array_key_exists('end', $data)) {
            $event['end'] = ['dateTime' => $data['end'], 'timeZone' => $timezone];
        }
        if (array_key_exists('location', $data)) {
            $event['location'] = ['displayName' => $data['location']];
        }
        if (array_key_exists('body', $data)) {
            $event['body'] = ['contentType' => 'text', 'content' => $data['body']];
        }
        if (array_key_exists('attendees', $data)) {
            $event['attendees'] = array_map(
                fn(string $email): array => [
                    'emailAddress' => ['address' => $email, 'name' => $email],
                    'type' => 'required'
                ],
                self::stringList($data['attendees'])
            );
        }
        if (array_key_exists('is_all_day', $data)) {
            $event['isAllDay'] = (bool) $data['is_all_day'];
        }
        if (array_key_exists('categories', $data)) {
            $event['categories'] = self::stringList($data['categories']);
        }
        return $event;
    }

    private function buildGraphTodoTask(array $data): array
    {
        $task = [];
        if (array_key_exists('title', $data)) {
            $task['title'] = $data['title'];
        }
        if (array_key_exists('status', $data)) {
            $task['status'] = $data['status'];
        }
        if (array_key_exists('importance', $data)) {
            $task['importance'] = $data['importance'];
        }
        if (array_key_exists('body', $data) && $data['body'] !== null) {
            $task['body'] = ['contentType' => 'text', 'content' => $data['body']];
        }
        if (array_key_exists('due', $data) && $data['due'] !== null) {
            $task['dueDateTime'] = [
                'dateTime' => $data['due'],
                'timeZone' => (string) ($data['timezone'] ?? 'UTC')
            ];
        }
        return $task;
    }

    private static function normalizeEmailAddresses(mixed $emails): array
    {
        if (!is_array($emails)) {
            return [];
        }
        $result = [];
        foreach ($emails as $email) {
            if (is_array($email) && isset($email['address'])) {
                $result[] = (string) $email['address'];
            }
            if (is_string($email)) {
                $result[] = $email;
            }
        }
        return self::stringList($result);
    }

    private static function normalizeAttendees(mixed $attendees): array
    {
        if (!is_array($attendees)) {
            return [];
        }
        $result = [];
        foreach ($attendees as $attendee) {
            if (!is_array($attendee)) {
                continue;
            }
            $result[] = [
                'email' => (string) ($attendee['emailAddress']['address'] ?? ''),
                'name' => (string) ($attendee['emailAddress']['name'] ?? ''),
                'type' => (string) ($attendee['type'] ?? ''),
                'status' => (string) ($attendee['status']['response'] ?? '')
            ];
        }
        return array_values(array_filter($result, fn(array $attendee): bool => $attendee['email'] !== '' || $attendee['name'] !== ''));
    }

    private static function stringList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            $value = [$value];
        }
        $value = array_map(fn(mixed $item): string => trim((string) $item), $value);
        return array_values(array_unique(array_filter($value, fn(string $item): bool => $item !== '')));
    }
}
