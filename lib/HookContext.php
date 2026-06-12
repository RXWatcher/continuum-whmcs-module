<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

use Silo\WhmcsModule\Config\ServerConfig;
use Silo\WhmcsModule\Silo\ClientInterface;
use Silo\WhmcsModule\Whmcs\CustomFieldStore;

/**
 * Aggregates the collaborators a hook method needs. Construction from
 * $params (production path) is via fromParams(); tests construct
 * directly with stubs.
 */
final class HookContext
{
    public function __construct(
        private ClientInterface $client,
        private Identity $identity,
        private AttributeMapper $mapper,
        private CustomFieldStore $customFields = new CustomFieldStore(),
        private ServerRegistry $servers = new ServerRegistry(),
        private HomeStore $homeStore = new HomeStore(),
        private PasswordResetThrottle $passwordResetThrottle = new PasswordResetThrottle(),
    ) {
    }

    public static function fromParams(array $params): self
    {
        $cfg = ServerConfig::fromParams($params);
        $client = new Client($cfg);
        return new self(
            $client,
            new Identity($client),
            new AttributeMapper(),
            new CustomFieldStore(),
            new ServerRegistry(),
            new HomeStore(),
            new PasswordResetThrottle(),
        );
    }

    public function client(): ClientInterface
    {
        return $this->client;
    }

    public function identity(): Identity
    {
        return $this->identity;
    }

    public function mapper(): AttributeMapper
    {
        return $this->mapper;
    }

    public function customFields(): CustomFieldStore
    {
        return $this->customFields;
    }

    public function servers(): ServerRegistry
    {
        return $this->servers;
    }

    public function homeStore(): HomeStore
    {
        return $this->homeStore;
    }

    public function passwordResetThrottle(): PasswordResetThrottle
    {
        return $this->passwordResetThrottle;
    }
}
