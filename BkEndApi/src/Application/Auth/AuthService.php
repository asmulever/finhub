<?php
declare(strict_types=1);

namespace FinHub\Application\Auth;

use FinHub\Domain\User\UserRepositoryInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Security\JwtTokenProvider;
use FinHub\Infrastructure\Security\PasswordHasher;

final class AuthService
{
    private UserRepositoryInterface $userRepository;
    private PasswordHasher $passwordHasher;
    private JwtTokenProvider $tokenProvider;
    private Config $config;

    public function __construct(
        UserRepositoryInterface $userRepository,
        PasswordHasher $passwordHasher,
        JwtTokenProvider $tokenProvider,
        Config $config
    ) {
        $this->userRepository = $userRepository;
        $this->passwordHasher = $passwordHasher;
        $this->tokenProvider = $tokenProvider;
        $this->config = $config;
    }

    /**
     * Valida credenciales y retorna token + metadatos del usuario.
     *
     * @throws \RuntimeException Si las credenciales son inválidas o el usuario está deshabilitado.
     */
    public function authenticate(string $email, string $password): array
    {
        $user = $this->userRepository->findByEmail($email);
        if ($user === null) {
            throw new \RuntimeException('Credenciales inválidas', 401);
        }

        if (!$user->isActive()) {
            throw new \RuntimeException('Usuario deshabilitado', 403);
        }

        if (!$this->passwordHasher->verify($password, $user->getPasswordHash())) {
            throw new \RuntimeException('Credenciales inválidas', 401);
        }

        $ttl = (int) $this->config->get('JWT_TTL_SECONDS', 3600);
        if ($ttl <= 0) {
            $ttl = 3600;
        }

        $tokenPayload = [
            'sub' => $user->getId(),
            'email' => $user->getEmail(),
            'role' => $user->getRole(),
        ];

        $token = $this->tokenProvider->issue($tokenPayload, $ttl);

        return [
            'token' => $token,
            'user' => $user->toResponse(),
        ];
    }
}
