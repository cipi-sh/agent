<?php

namespace Cipi\Agent\Mcp;

use Cipi\Agent\Mcp\Resources\AnonymizationConfigResource;
use Cipi\Agent\Mcp\Resources\AnonymizationSchemaResource;
use Cipi\Agent\Mcp\Resources\CipiYmlExampleResource;
use Cipi\Agent\Mcp\Resources\CipiYmlResource;
use Cipi\Agent\Mcp\Resources\CipiYmlSchemaResource;
use Cipi\Agent\Mcp\Tools\AnonymizerTool;
use Cipi\Agent\Mcp\Tools\AppInfoTool;
use Cipi\Agent\Mcp\Tools\ArtisanTool;
use Cipi\Agent\Mcp\Tools\CipiYmlTool;
use Cipi\Agent\Mcp\Tools\DbQueryTool;
use Cipi\Agent\Mcp\Tools\DbSchemaTool;
use Cipi\Agent\Mcp\Tools\DeployStatusTool;
use Cipi\Agent\Mcp\Tools\DeployTool;
use Cipi\Agent\Mcp\Tools\EnvCheckTool;
use Cipi\Agent\Mcp\Tools\HealthTool;
use Cipi\Agent\Mcp\Tools\LogsTool;
use Cipi\Agent\Mcp\Tools\Panel\PanelDbBackupTool;
use Cipi\Agent\Mcp\Tools\Panel\PanelDeployAuditTool;
use Cipi\Agent\Mcp\Tools\Panel\PanelJobTool;
use Cipi\Agent\Mcp\Tools\Panel\PanelRollbackTool;
use Cipi\Agent\Mcp\Tools\Panel\PanelStatusTool;

/**
 * The ordered set of tools and resources this server exposes.
 */
final class Registry
{
    /** @var list<class-string<Tool>> */
    public const TOOLS = [
        HealthTool::class,
        AppInfoTool::class,
        DeployTool::class,
        DeployStatusTool::class,
        LogsTool::class,
        ArtisanTool::class,
        DbQueryTool::class,
        DbSchemaTool::class,
        EnvCheckTool::class,
        CipiYmlTool::class,
        AnonymizerTool::class,
        PanelStatusTool::class,
        PanelDeployAuditTool::class,
        PanelRollbackTool::class,
        PanelJobTool::class,
        PanelDbBackupTool::class,
    ];

    /** @var list<class-string<Resource>> */
    public const RESOURCES = [
        CipiYmlResource::class,
        CipiYmlSchemaResource::class,
        CipiYmlExampleResource::class,
        AnonymizationConfigResource::class,
        AnonymizationSchemaResource::class,
    ];

    /** @var list<Tool>|null */
    private ?array $tools = null;

    /** @var list<Resource>|null */
    private ?array $resources = null;

    /**
     * @param  list<class-string<Tool>>  $toolClasses
     * @param  list<class-string<Resource>>  $resourceClasses
     */
    public function __construct(
        private readonly array $toolClasses = self::TOOLS,
        private readonly array $resourceClasses = self::RESOURCES,
    ) {}

    /** @return list<Tool> */
    public function tools(): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            foreach ($this->toolClasses as $class) {
                $tool = app($class);
                if ($tool instanceof Tool && $tool->available()) {
                    $this->tools[] = $tool;
                }
            }
        }

        return $this->tools;
    }

    public function tool(string $name): ?Tool
    {
        foreach ($this->tools() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /** @return list<Resource> */
    public function resources(): array
    {
        if ($this->resources === null) {
            $this->resources = [];
            foreach ($this->resourceClasses as $class) {
                $resource = app($class);
                if ($resource instanceof Resource && $resource->available()) {
                    $this->resources[] = $resource;
                }
            }
        }

        return $this->resources;
    }

    public function resource(string $uri): ?Resource
    {
        foreach ($this->resources() as $resource) {
            if ($resource->uri() === $uri) {
                return $resource;
            }
        }

        return null;
    }
}
