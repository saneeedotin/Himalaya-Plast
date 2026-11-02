<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppError;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Push;

/**
 * What needs attention, for the signed-in person only: alerts the system raises (stock low, order due, invoices
 * overdue) and events as they happen (order confirmed, machine stopped, QC reject, payment received...).
 * Read status is per person. The page polls /notifications/poll for live pop-ups, and browsers that agreed
 * to Web Push are told even with the app closed.
 */
final class NotificationController extends Controller
{
    public function index(): void
    {
        $this->runAlertsIfDue();
        $show = ($_GET['show'] ?? '') === 'all' ? 'all' : 'open';
        $rows = Db::rows('sp_notification_list', [$this->uid(), 200]);
        $this->render('notifications/index', ['title' => 'Notifications', 'rows' => $rows, 'show' => $show]);
    }

    public function setStatus(array $p): void
    {
        $status = $this->str('status');
        if (!in_array($status, ['READ', 'RESOLVED', 'UNREAD'], true)) {
            throw new AppError('Choose read or resolved.');
        }
        Db::call('sp_notification_set_status', [$this->uid(), $p['id'], $status]);
        unset($_SESSION['unread']);
        redirect($this->backTo('/notifications'));
    }

    public function readAll(): void
    {
        Db::call('sp_notification_set_status', [$this->uid(), null, 'READ']);
        unset($_SESSION['unread']);
        Flash::success('All notifications marked as read.');
        redirect('/notifications');
    }

    /** JSON for the live pop-ups: this person's unread count, the server time, and what is new since `after`. */
    public function poll(): void
    {
        $this->runAlertsIfDue();
        $after = (string) ($_GET['after'] ?? '');
        $after = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $after) ? $after : null;
        $sets = Db::call('sp_notification_poll', [$this->uid(), $after]);
        $items = [];
        foreach ($sets[1] ?? [] as $n) {
            $items[] = [
                'id' => $n['id'], 'title' => $n['title'], 'message' => mb_substr((string) $n['message'], 0, 240), 'priority' => $n['priority'],
                'url' => url((string) ($n['action_url'] ?: '/notifications')), 'created_at' => $n['created_at'],
            ];
        }
        $_SESSION['unread'] = ['n' => (int) $sets[0][0]['unread'], 'at' => time(), 'user' => $this->uid()];
        $this->json(['unread' => (int) $sets[0][0]['unread'], 'now' => $sets[0][0]['server_time'], 'items' => $items]);
    }

    // ------------------------------------------------------------------ Web Push

    /** Is push possible on this server, and how many browsers has this person registered? */
    public function pushStatus(): void
    {
        $this->json($this->pushFacts((string) ($_POST['endpoint'] ?? '')));
    }

    public function pushSubscribe(): void
    {
        $endpoint = (string) ($_POST['endpoint'] ?? '');
        if (!Push::isAllowedEndpoint($endpoint)) {
            $this->json(['ok' => false, 'error' => 'That is not a browser push service address.'], 422);
        }
        try {
            Db::call('sp_push_subscribe', [$this->uid(), $endpoint, (string) ($_POST['p256dh'] ?? ''), (string) ($_POST['auth'] ?? ''), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        } catch (AppError $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $this->json(['ok' => true] + $this->pushFacts($endpoint));
    }

    public function pushUnsubscribe(): void
    {
        $endpoint = (string) ($_POST['endpoint'] ?? '');
        Db::call('sp_push_unsubscribe', [$this->uid(), $endpoint]);
        $this->json(['ok' => true] + $this->pushFacts($endpoint));
    }

    /** Sends a test push to every browser this person registered, and says what each push service answered. */
    public function pushTest(): void
    {
        if (!self::pushAvailable()) {
            $this->json(['ok' => false, 'error' => 'Web Push is not set up on this server.'], 503);
        }
        $subs = Db::rows('sp_push_mine', [$this->uid()]);
        if (!$subs) {
            $this->json(['ok' => false, 'error' => 'No browser is registered yet. Turn push on first.'], 409);
        }
        $payload = (string) json_encode(['title' => 'HPOS test notification', 'body' => 'If you can read this, push notifications work on this browser.', 'url' => url('/notifications'), 'tag' => 'hpos-test', 'priority' => 'UPDATE'], JSON_UNESCAPED_SLASHES);
        $results = [];
        foreach ($subs as $s) {
            $r = Push::send($s, $payload, 300);
            if ($r['status'] === 404 || $r['status'] === 410) {
                Db::call('sp_push_drop', [$s['id']]);
            } elseif ($r['status'] >= 200 && $r['status'] < 300) {
                Db::call('sp_push_ok', [$s['id']]);
            }
            $results[] = ['status' => $r['status'], 'error' => $r['error']];
        }
        $accepted = count(array_filter($results, static fn (array $r): bool => $r['status'] >= 200 && $r['status'] < 300));
        $this->json(['ok' => $accepted > 0, 'accepted' => $accepted, 'total' => count($results), 'results' => $results]);
    }

    /** True when this server can send pushes: openssl can make and use the VAPID keys. */
    public static function pushAvailable(): bool
    {
        try {
            Push::keys();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function pushFacts(string $endpoint): array
    {
        $available = self::pushAvailable();
        $row = Db::row('sp_push_status', [$this->uid(), $endpoint]) ?? ['browsers' => 0, 'this_browser' => 0];
        return [
            'available' => $available,
            'key' => $available ? Push::keys()['public'] : '',
            'browsers' => (int) $row['browsers'],
            'thisBrowser' => (int) $row['this_browser'] > 0,
        ];
    }

    /** Alerts such as "stock low" refresh themselves every ten minutes while anyone has the app open, with or without cron. */
    private function runAlertsIfDue(): void
    {
        try {
            if ((int) (Db::row('sp_alerts_run_if_due', [10])['ran'] ?? 0) === 1) {
                Push::dispatchPending();
            }
        } catch (\Throwable) {
            // the page still works without a fresh check
        }
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
