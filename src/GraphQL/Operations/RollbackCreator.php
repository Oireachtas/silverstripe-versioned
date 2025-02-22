<?php

namespace SilverStripe\Versioned\GraphQL\Operations;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Field\ModelMutation;
use SilverStripe\GraphQL\Schema\Interfaces\ModelOperation;
use SilverStripe\GraphQL\Schema\Interfaces\OperationCreator;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaModelInterface;
use SilverStripe\Versioned\GraphQL\Resolvers\VersionedResolver;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ViewableData;
use SilverStripe\Dev\Deprecation;

// GraphQL dependency is optional in versioned,
// and the following implementation relies on existence of this class (in GraphQL v4)
if (!interface_exists(OperationCreator::class)) {
    return;
}

/**
 * Scaffolds a "rollback recursive" operation for DataObjects.
 *
 * rollback[TypeName](ID!, ToVersion!)
 *
 * @deprecated 5.3.0 Will be moved to the silverstripe/graphql module
 */
class RollbackCreator implements OperationCreator
{
    use Injectable;
    use Configurable;

    public function __construct()
    {
        Deprecation::withNoReplacement(function () {
            Deprecation::notice('5.3.0', 'Will be moved to the silverstripe/graphql module', Deprecation::SCOPE_CLASS);
        });
    }

    /**
     * @var array
     * @config
     */
    private static $default_plugins = [];

    /**
     * @param SchemaModelInterface $model
     * @param string $typeName
     * @param array $config
     * @return ModelOperation|null
     * @throws SchemaBuilderException
     */
    public function createOperation(
        SchemaModelInterface $model,
        string $typeName,
        array $config = []
    ): ?ModelOperation {
        if (!ViewableData::has_extension($model->getSourceClass(), Versioned::class)) {
            return null;
        }

        $defaultPlugins = $this->config()->get('default_plugins');
        $configPlugins = $config['plugins'] ?? [];
        $plugins = array_merge($defaultPlugins, $configPlugins);
        $mutationName = 'rollback' . ucfirst($typeName ?? '');
        return ModelMutation::create($model, $mutationName)
            ->setPlugins($plugins)
            ->setType($typeName)
            ->setResolver([VersionedResolver::class, 'resolveRollback'])
            ->addResolverContext('dataClass', $model->getSourceClass())
            ->addArg('id', [
                'type' => 'ID!',
                'description' => 'The object ID that needs to be rolled back'
            ])
            ->addArg('toVersion', [
                'type' => 'Int!',
                'description' => 'The version of the object that should be rolled back to',
            ]);
    }
}
