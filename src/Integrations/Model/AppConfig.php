<?php

declare(strict_types=1);

namespace Packeton\Integrations\Model;

use Packeton\Entity\OAuthIntegration;

class AppConfig
{
    public function __construct(protected array $config)
    {
    }

    public function isRegistration(): bool
    {
        return ($this->config['allow_register'] ?? false);
    }

    public function clonePref(): string
    {
        return $this->config['clone_preference'] ?? 'api';
    }

    public function enableSync(): bool
    {
        return $this->config['repos_synchronization'] ?? false;
    }

    public function isPullRequestReview()
    {
        return $this->config['pull_request_review'] ?? false;
    }

    public function isLogin(): bool
    {
        return $this->config['allow_login'] ?? false;
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    public function getClientId(): ?string
    {
        return $this->config['client_id'] ?? null;
    }

    public function getCaps(OAuthIntegration $app = null): array
    {
        $caps = ['APP' => true, 'ALLOW_LOGIN' => $this->isLogin(), 'ALLOW_REGISTRATION' => $this->isRegistration()];
        if (null !== $app) {
            $caps['PR_REVIEW_ENABLED'] = AppUtils::enableReview($this, $app);
            $caps['AUTO_SYNC_ENABLED'] = AppUtils::enableSync($this, $app);
        }

        return array_keys(array_filter($caps));
    }

    public function getClientSecret(): ?string
    {
        return $this->config['client_secret'] ?? null;
    }

    public function roles(): array
    {
        return $this->config['default_roles'] ?? [];
    }

    public function getLogo(): ?string
    {
        return $this->config['logo'] ?? null;
    }

    public function getTitle(): ?string
    {
        return $this->config['title'] ?? null;
    }

    public function getName(): string
    {
        return $this->config['name'];
    }

    public function getRedirectUrls(): array
    {
        return $this->config['redirect_urls'] ?? [];
    }

    public function getHookUrl(): ?string
    {
        return $this->config['hook_url'] ?? null;
    }
}