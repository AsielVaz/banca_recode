<?php
declare(strict_types=1);

namespace Banca\Service;

use Banca\Core\Auth;
use Banca\Repository\BankingRepository;
use RuntimeException;

final class AuthService
{
    public function __construct(
        private readonly BankingRepository $repository,
        private readonly TotpService $totp = new TotpService(),
    ) {
    }

    public function attempt(string $email, string $password, string $code): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new RuntimeException('Correo o contraseña incorrectos.');
        }
        if ($this->repository->failedLogins($email) >= 6) {
            throw new RuntimeException('Acceso bloqueado temporalmente por demasiados intentos. Inténtalo en una hora.');
        }
        $user = $this->repository->userByEmail($email);
        $validPassword = $user !== false && $this->verifyPassword($password, (string) $user['password'], $email);
        $secret = $user !== false ? trim((string) ($user['2fa'] ?? '')) : '';
        $validTotp = $secret === '' || $this->totp->verify($secret, $code, 1);
        if (!$validPassword || !$validTotp || (int) ($user['cliente'] ?? 0) <= 0) {
            $this->repository->logLogin($email, false);
            throw new RuntimeException($validPassword && !$validTotp ? 'El código 2FA no es válido.' : 'Correo o contraseña incorrectos.');
        }
        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            $this->repository->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        $this->repository->logLogin($email, true);
        Auth::login($user);
        if ($secret === '') {
            $_SESSION['security_notice'] = 'Configura 2FA antes de realizar retiros.';
        }
    }

    public function verifyCurrentPassword(int $userId, string $password): bool
    {
        $user = $this->repository->userById($userId);
        return $user !== false && $this->verifyPassword($password, (string) $user['password'], (string) $user['email']);
    }

    private function verifyPassword(string $plain, string $stored, string $email): bool
    {
        if (str_starts_with($stored, '$')) {
            return password_verify($plain, $stored);
        }
        $legacy = sha1(md5(sha1($email . $plain)));
        return hash_equals($stored, $legacy);
    }
}
