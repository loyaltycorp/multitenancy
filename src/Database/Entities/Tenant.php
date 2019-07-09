<?php
declare(strict_types=1);

namespace LoyaltyCorp\Mulitenancy\Database\Entities;

use Doctrine\ORM\Mapping as ORM;

class Tenant
{
    /**
     * @var int Internal Database ID.
     */
    private $id;

    /**
     * @var string The immutable external ID.
     */
    private $externalId;

    /**
     * @var string Common name of provider.
     */
    private $name;

    /**
     * Tenant constructor.
     *
     * @param string $externalId External ID to find this tenant by.
     * @param string $name Common name of the tenant.
     */
    public function __construct(string $externalId, string $name) {
        $this->externalId = $externalId;
        $this->name = $name;
    }

    /**
     * Get the External ID of the tenant. This is generated by an external system (usually ManageV2).
     *
     * @return string
     */
    public function getExternalId(): string {
        return $this->externalId;
    }

    /**
     * Get the common name of the tenant. eg; "LoyaltyCorp", "RACQ", or "Optus".
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Update common name of the tenant.
     *
     * @param string $name
     */
    public function setName(string $name): void {
        $this->name = $name;
    }
}
