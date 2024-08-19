<?php

namespace SilverStripe\Versioned\GraphQL\Plugins;

use SilverStripe\GraphQL\Schema\DataObject\Plugin\Paginator;
use SilverStripe\GraphQL\Schema\DataObject\Plugin\ScalarDBField;
use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Field\Field;
use SilverStripe\GraphQL\Schema\Field\ModelField;
use SilverStripe\GraphQL\Schema\Interfaces\ModelTypePlugin;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaUpdater;
use SilverStripe\GraphQL\Schema\Plugin\AbstractQuerySortPlugin;
use SilverStripe\GraphQL\Schema\Plugin\PaginationPlugin;
use SilverStripe\GraphQL\Schema\Plugin\SortPlugin;
use SilverStripe\GraphQL\Schema\Schema;
use SilverStripe\GraphQL\Schema\Type\InputType;
use SilverStripe\GraphQL\Schema\Type\ModelType;
use SilverStripe\GraphQL\Schema\Type\Type;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Sortable;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\GraphQL\Resolvers\VersionedResolver;
use SilverStripe\Versioned\Versioned;
use Closure;
use SilverStripe\View\ViewableData;
use SilverStripe\Dev\Deprecation;

// GraphQL dependency is optional in versioned,
// and the following implementation relies on existence of this class (in GraphQL v4)
if (!interface_exists(ModelTypePlugin::class)) {
    return;
}

/**
 * @deprecated 5.3.0 Will be moved to the silverstripe/graphql module
 */
class VersionedDataObject implements ModelTypePlugin, SchemaUpdater
{
    const IDENTIFIER = 'versioning';

    public function __construct()
    {
        Deprecation::withNoReplacement(function () {
            Deprecation::notice('5.3.0', 'Will be moved to the silverstripe/graphql module', Deprecation::SCOPE_CLASS);
        });
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return VersionedDataObject::IDENTIFIER;
    }

    /**
     * @param Schema $schema
     * @throws SchemaBuilderException
     */
    public static function updateSchema(Schema $schema): void
    {
        $schema->addModelbyClassName(Member::class);
        // Hack.. we can't add a plugin within a plugin, so we have to add sort
        // and pagination manually. This requires ensuring the sort types are added
        // to the schema (most of the time this is redundant)
        if (!$schema->getType('SortDirection')) {
            AbstractQuerySortPlugin::updateSchema($schema);
        }
        if (!$schema->getType('PageInfo')) {
            PaginationPlugin::updateSchema($schema);
        }
    }

    /**
     * @param ModelType $type
     * @param Schema $schema
     * @param array $config
     * @throws SchemaBuilderException
     */
    public function apply(ModelType $type, Schema $schema, array $config = []): void
    {
        $class = $type->getModel()->getSourceClass();
        Schema::invariant(
            is_subclass_of($class, DataObject::class),
            'The %s plugin can only be applied to types generated by %s models',
            __CLASS__,
            DataObject::class
        );
        if (!ViewableData::has_extension($class, Versioned::class)) {
            return;
        }

        $versionName = $type->getModel()->getTypeName() . 'Version';
        $memberType = $schema->getModelByClassName(Member::class);
        Schema::invariant(
            $memberType,
            'The %s class was not added as a model. Should have been done in %s::%s?',
            Member::class,
            __CLASS__,
            'updateSchema'
        );
        $memberTypeName = $memberType->getModel()->getTypeName();
        $resolver = ['resolver' => [VersionedResolver::class, 'resolveVersionFields']];

        $type->addField('version', 'Int', function (ModelField $field) {
            $field->addResolverAfterware([ScalarDBField::class, 'resolve']);
        });

        $versionType = Type::create($versionName)
            ->addField('author', ['type' => $memberTypeName] + $resolver)
            ->addField('publisher', ['type' => $memberTypeName] + $resolver)
            ->addField('published', ['type' => 'Boolean'] + $resolver)
            ->addField('liveVersion', ['type' => 'Boolean'] + $resolver)
            ->addField('deleted', ['type' => 'Boolean'] + $resolver)
            ->addField('draft', ['type' => 'Boolean'] + $resolver)
            ->addField('latestDraftVersion', ['type' => 'Boolean'] + $resolver);

        foreach ($type->getFields() as $field) {
            $clone = clone $field;
            $versionType->addField($clone->getName(), $clone);
        }
        foreach ($type->getInterfaces() as $interface) {
            $versionType->addInterface($interface);
        }

        $schema->addType($versionType);
        $type->addField('versions', '[' . $versionName . ']', function (Field $field) use ($type, $schema, $config) {
                $field->setResolver([VersionedResolver::class, 'resolveVersionList'])
                    ->addResolverContext('sourceClass', $type->getModel()->getSourceClass());
                SortPlugin::singleton()->apply($field, $schema, [
                    'resolver' => [static::class, 'sortVersions'],
                    'fields' => [ 'version' => true ],
                ]);
                Paginator::singleton()->apply($field, $schema);
        });
    }

    /**
     * @param array $config
     * @return Closure
     */
    public static function sortVersions(array $config): Closure
    {
        $fieldName = $config['fieldName'];
        return function (Sortable $list, array $args) use ($fieldName) {
            $versionSort = $args[$fieldName]['version'] ?? null;
            if ($versionSort) {
                $list = $list->sort('Version', $versionSort);
            }

            return $list;
        };
    }
}
