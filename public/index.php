<?php
declare(strict_types=1);

use Banca\Core\Auth;
use Banca\Core\Csrf;
use Banca\Core\Database;
use Banca\Core\Env;
use Banca\Core\Flash;
use Banca\Core\Http;
use Banca\Core\Validation;
use Banca\Core\View;
use Banca\Repository\BankingRepository;
use Banca\Service\AuthService;
use Banca\Service\BankResolver;
use Banca\Service\BatchTemplateService;
use Banca\Service\OperationToken;
use Banca\Service\TotpService;
use Banca\Service\TransferService;
use Banca\Service\WithdrawalService;
use Banca\Service\WithdrawalConcept;

require dirname(__DIR__) . '/src/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

$route = Http::route();
$method = Http::method();

if ($route === '/health' && $method === 'GET') {
    try {
        Database::connection()->query('SELECT 1')->fetchColumn();
        Http::json(['status' => 'ok', 'database' => 'available']);
    } catch (Throwable) {
        Http::json(['status' => 'unavailable', 'database' => 'unavailable'], 503);
    }
}

$repository = BankingRepository::make();

/** @param callable():void $callback */
function postAction(callable $callback, string $success, string $failureRoute): never
{
    try {
        Csrf::verify();
        $callback();
        Flash::set('success', $success);
    } catch (Throwable $exception) {
        Flash::set('error', $exception->getMessage());
    }
    Http::redirect($failureRoute);
}

if ($route === '/login' && $method === 'GET') {
    if (Auth::check()) {
        Http::redirect('/');
    }
    View::render('login', ['title' => 'Acceso seguro'], false);
    exit;
}

if ($route === '/login' && $method === 'POST') {
    postAction(static function () use ($repository): void {
        (new AuthService($repository))->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['totp'] ?? ''));
    }, 'Sesión iniciada correctamente.', '/');
}

if ($route === '/logout' && $method === 'POST') {
    Csrf::verify();
    Auth::logout();
    Http::redirect('/login');
}

Auth::requireUser();
$user = Auth::user();
$balance = $repository->balance(Auth::clientId());

if ($route === '/' && $method === 'GET') {
    View::render('dashboard', [
        'title' => 'Resumen',
        'balance' => $balance,
        'movements' => $repository->movements(Auth::clientId(), 5),
        'withdrawals' => $repository->withdrawals(Auth::clientId(), 5),
        'canTransfer' => $repository->canInitiateTransfers(Auth::clientId()),
        'securityNotice' => $_SESSION['security_notice'] ?? null,
    ]);
    unset($_SESSION['security_notice']);
    exit;
}

if ($route === '/movements' && $method === 'GET') {
    View::render('movements', ['title' => 'Movimientos', 'balance' => $balance, 'movements' => $repository->movements(Auth::clientId(), 300)]);
    exit;
}

if ($route === '/receipts' && $method === 'GET') {
    View::render('receipts', ['title' => 'Retiros y comprobantes', 'balance' => $balance, 'withdrawals' => $repository->withdrawals(Auth::clientId(), 300)]);
    exit;
}

if ($route === '/contacts' && $method === 'GET') {
    $edit = isset($_GET['id']) ? $repository->contact((int) $_GET['id'], Auth::id()) : false;
    View::render('contacts', ['title' => 'Contactos', 'balance' => $balance, 'contacts' => $repository->contacts(Auth::id()), 'edit' => $edit]);
    exit;
}

if ($route === '/contacts/save' && $method === 'POST') {
    postAction(static function () use ($repository): void {
        $repository->saveContact(Auth::id(), [
            'numero' => Validation::account($_POST['numero'] ?? ''),
            'nombre' => Validation::text($_POST['nombre'] ?? '', 'Nombre', 100),
            'correo' => Validation::email($_POST['correo'] ?? ''),
            'telefono' => Validation::text($_POST['telefono'] ?? '', 'Teléfono', 100, false),
            'concepto' => Validation::text($_POST['concepto'] ?? '', 'Concepto', 100, false),
        ], !empty($_POST['id']) ? (int) $_POST['id'] : null);
    }, 'Contacto guardado.', '/contacts');
}

if ($route === '/contacts/delete' && $method === 'POST') {
    postAction(static function () use ($repository): void {
        $repository->deleteContact((int) ($_POST['id'] ?? 0), Auth::id());
    }, 'Contacto eliminado.', '/contacts');
}

if ($route === '/transfer' && $method === 'GET') {
    View::render('transfer', [
        'title' => 'Traspaso entre carteras',
        'balance' => $balance,
        'clients' => $repository->destinationClients(Auth::clientId()),
        'canTransfer' => $repository->canInitiateTransfers(Auth::clientId()),
    ]);
    exit;
}

if ($route === '/transfer' && $method === 'POST') {
    postAction(static function (): void {
        (new TransferService())->execute(
            Auth::clientId(),
            (int) ($_POST['destination'] ?? 0),
            Auth::id(),
            Validation::money($_POST['amount'] ?? ''),
            Validation::text($_POST['detail'] ?? '', 'Detalle', 500, false),
        );
    }, 'Traspaso realizado correctamente.', '/transfer');
}

if ($route === '/withdraw' && $method === 'GET') {
    View::render('withdraw', [
        'title' => 'Nuevo retiro SPEI', 'balance' => $balance, 'contacts' => $repository->contacts(Auth::id()),
        'banks' => $repository->banks(), 'speiMode' => Env::get('SPEI_MODE', 'disabled'),
        'operationToken' => OperationToken::issue('withdraw'),
    ]);
    exit;
}

if ($route === '/bank/resolve' && $method === 'POST') {
    try {
        Csrf::verify();
        $account = Validation::account($_POST['account'] ?? '');
        $bank = (new BankResolver($repository))->resolve($account);
        Http::json(['ok' => true, 'bank' => $bank]);
    } catch (Throwable $exception) {
        Http::json(['ok' => false, 'message' => $exception->getMessage()], 422);
    }
}

if ($route === '/withdraw' && $method === 'POST') {
    postAction(static function () use ($repository): void {
        $operation = $_POST['operation'] ?? '';
        $account = Validation::account($_POST['account'] ?? '');
        $bank = (new BankResolver($repository))->resolve($account);
        (new WithdrawalService($repository))->execute(Auth::id(), Auth::clientId(), [
            'name' => Validation::text($_POST['name'] ?? '', 'Beneficiario', 40),
            'account' => $account,
            'amount' => Validation::money($_POST['amount'] ?? ''),
            'concept' => WithdrawalConcept::normalize($_POST['concept'] ?? ''),
            'email' => Validation::email($_POST['email'] ?? ''),
            'phone' => Validation::text($_POST['phone'] ?? '', 'Teléfono', 20, false),
            'bank' => $bank['code'],
            'totp' => Validation::text($_POST['totp'] ?? '', 'Código 2FA', 6),
            'reference' => OperationToken::reference('withdraw', $operation),
        ]);
        OperationToken::complete('withdraw', $operation);
    }, 'Retiro registrado correctamente.', '/receipts');
}

if ($route === '/batch' && $method === 'GET') {
    View::render('batch', [
        'title' => 'Retiro por plantilla',
        'balance' => $balance,
        'speiMode' => Env::get('SPEI_MODE', 'disabled'),
        'operationToken' => OperationToken::issue('batch'),
    ]);
    exit;
}

if ($route === '/batch/template' && $method === 'GET') {
    $template = BANCA_ROOT . '/public/assets/plantilla.xls';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="plantilla.xls"');
    header('Content-Length: ' . filesize($template));
    readfile($template);
    exit;
}

if ($route === '/batch/preview' && $method === 'POST') {
    try {
        Csrf::verify();
        $result = (new BatchTemplateService(new BankResolver($repository)))->parseUpload($_FILES['file'] ?? [], $balance);
        Http::json([
            'ok' => true,
            'valid' => $result['valid'],
            'rows' => array_map([BatchTemplateService::class, 'publicRow'], $result['rows']),
            'summary' => [
                'count' => $result['count'],
                'total' => $result['total'],
                'balance' => $balance,
                'after' => round($balance - $result['total'], 2),
            ],
        ]);
    } catch (Throwable $exception) {
        Http::json(['ok' => false, 'message' => $exception->getMessage()], 422);
    }
}

if ($route === '/batch' && $method === 'POST') {
    postAction(static function () use ($repository): void {
        $operation = $_POST['operation'] ?? '';
        $parsed = (new BatchTemplateService(new BankResolver($repository)))->parseUpload(
            $_FILES['file'] ?? [],
            $repository->balance(Auth::clientId()),
        );
        if (!$parsed['valid']) {
            $errors = array_values(array_filter($parsed['rows'], static fn (array $row): bool => !$row['valid']));
            $first = $errors[0] ?? ['row' => '?', 'error' => 'Error desconocido'];
            throw new RuntimeException("Corrige la fila {$first['row']}: {$first['error']}");
        }
        $totp = Validation::text($_POST['totp'] ?? '', 'Código 2FA', 6);
        $service = new WithdrawalService($repository);
        foreach ($parsed['rows'] as $index => $row) {
            $service->execute(Auth::id(), Auth::clientId(), $row + [
                'totp' => $totp,
                'reference' => OperationToken::reference('batch', $operation, $index + 1),
            ]);
        }
        OperationToken::complete('batch', $operation);
    }, 'Retiro por plantilla procesado correctamente.', '/receipts');
}

if ($route === '/security' && $method === 'GET') {
    $current = $repository->userById(Auth::id());
    $secret = $_SESSION['pending_totp'] ?? null;
    View::render('security', [
        'title' => 'Seguridad', 'balance' => $balance, 'hasTotp' => $current !== false && trim((string) $current['2fa']) !== '',
        'pendingSecret' => $secret, 'pendingUri' => $secret ? (new TotpService())->uri((string) $secret, (string) ($current['email'] ?? '')) : null,
    ]);
    exit;
}

if ($route === '/security/password' && $method === 'POST') {
    postAction(static function () use ($repository): void {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        if (!(new AuthService($repository))->verifyCurrentPassword(Auth::id(), $current)) {
            throw new RuntimeException('La contraseña actual no es correcta.');
        }
        if (strlen($new) < 12 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/\d/', $new)) {
            throw new RuntimeException('La nueva contraseña debe tener al menos 12 caracteres, mayúscula, minúscula y número.');
        }
        $repository->updatePassword(Auth::id(), password_hash($new, PASSWORD_DEFAULT));
    }, 'Contraseña actualizada.', '/security');
}

if ($route === '/security/totp/start' && $method === 'POST') {
    postAction(static function (): void {
        $_SESSION['pending_totp'] = (new TotpService())->generateSecret();
    }, 'Escanea o captura la clave y confirma el código.', '/security');
}

if ($route === '/security/totp/enable' && $method === 'POST') {
    postAction(static function () use ($repository): void {
        $secret = (string) ($_SESSION['pending_totp'] ?? '');
        if ($secret === '' || !(new TotpService())->verify($secret, (string) ($_POST['totp'] ?? ''), 1)) {
            throw new RuntimeException('El código no coincide. La configuración no fue guardada.');
        }
        $repository->updateTotp(Auth::id(), $secret);
        unset($_SESSION['pending_totp']);
    }, 'Autenticación 2FA activada.', '/security');
}

http_response_code(404);
View::render('not-found', ['title' => 'Página no encontrada']);
