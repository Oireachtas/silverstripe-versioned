<?php


namespace SilverStripe\Versioned\GraphQL\Resolvers;

use GraphQL\Type\Definition\ResolveInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\GraphQL\QueryHandler\QueryHandler;
use SilverStripe\GraphQL\QueryHandler\UserContextProvider;
use SilverStripe\GraphQL\Resolvers\VersionFilters;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\GraphQL\Operations\AbstractPublishOperationCreator;
use SilverStripe\Versioned\GraphQL\Operations\CopyToStageCreator;
use SilverStripe\Versioned\GraphQL\Operations\RollbackCreator;
use SilverStripe\Versioned\GraphQL\Plugins\VersionedDataObject;
use SilverStripe\Versioned\GraphQL\Plugins\VersionedRead;
use SilverStripe\Versioned\Versioned;
use Exception;
use Closure;
use InvalidArgumentException;
use SilverStripe\Dev\Deprecation;

/**
 * @deprecated 5.3.0 Will be moved to the silverstripe/graphql module
 */
class VersionedResolver
{
    private static $priority = 1;

    public function __construct()
    {
        Deprecation::withNoReplacement(function () {
            Deprecation::notice('5.3.0', 'Will be moved to the silverstripe/graphql module', Deprecation::SCOPE_CLASS);
        });
    }

    /**
     * @param DataObject $obj
     * @param array $args
     * @param array $context
     * @param ResolveInfo $info
     * @return mixed|null
     * @see VersionedDataObject
     */
    public static function resolveVersionFields(DataObject $obj, array $args, array $context, ResolveInfo $info)
    {
        /* @var DataObject&Versioned $obj */
        switch ($info->fieldName) {
            case 'author':
                return $obj->Author();
            case 'publisher':
                return $obj->Publisher();
            case 'published':
                return $obj->WasPublished;
            case 'draft':
                return $obj->WasDraft;
            case 'deleted':
                return $obj->WasDeleted;
            case 'liveVersion':
                return $obj->isLiveVersion();
            case 'latestDraftVersion':
                return $obj->isLatestDraftVersion();
        }

        return null;
    }

    /**
     * @param array $resolverContext
     * @return Closure
     * @see VersionedDataObject
     */
    public static function resolveVersionList(array $resolverContext): Closure
    {
        $sourceClass = $resolverContext['sourceClass'];
        return function ($object, array $args, array $context, ResolveInfo $info) use ($sourceClass) {
            /** @var DataObject|Versioned $object */
            if (!$object->hasExtension(Versioned::class)) {
                throw new Exception(sprintf(
                    'Types using the %s plugin must have the Versioned extension applied. (See %s)',
                    VersionedDataObject::class,
                    $sourceClass
                ));
            }
            $member = UserContextProvider::get($context);
            if (!$object->canViewStage(Versioned::DRAFT, $member)) {
                throw new Exception(sprintf(
                    'Cannot view versions on %s',
                    $sourceClass
                ));
            }

            // Get all versions
            return VersionedResolver::getVersionsList($object);
        };
    }

    private static function getVersionsList(DataObject $object)
    {
        $id = $object->ID ?: $object->OldID;
        $class = DataObject::getSchema()->baseDataClass($object);
        return Versioned::get_all_versions($class, $id);
    }

    /**
     * @template T of DataObject
     * @param DataList<T> $list
     * @param array $args
     * @param array $context
     * @param ResolveInfo $info
     * @return DataList<T>
     * @see VersionedRead
     */
    public static function resolveVersionedRead(DataList $list, array $args, array $context, ResolveInfo $info)
    {
        if (!isset($args['versioning'])) {
            return $list;
        }

        // Set the reading state globally, we don't support mixing versioned states in the same query
        Injector::inst()->get(VersionFilters::class)
            ->applyToReadingState($args['versioning']);

        // Also set on the specific list
        $list = Injector::inst()->get(VersionFilters::class)
            ->applyToList($list, $args['versioning']);

        return $list;
    }

    /**
     * @param array $context
     * @return Closure
     * @see CopyToStageCreator
     */
    public static function resolveCopyToStage(array $context): Closure
    {
        $dataClass = $context['dataClass'] ?? null;
        return function ($object, array $args, $context, ResolveInfo $info) use ($dataClass) {
            if (!$dataClass) {
                return;
            }

            $input = $args['input'];
            $id = $input['id'];
            $to = $input['toStage'];
            /** @var Versioned|DataObject $record */
            $record = null;
            if (isset($input['fromVersion'])) {
                $from = $input['fromVersion'];
                $record = Versioned::get_version($dataClass, $id, $from);
            } elseif (isset($input['fromStage'])) {
                $from = $input['fromStage'];
                $record = Versioned::get_by_stage($dataClass, $from)->byID($id);
            } else {
                throw new InvalidArgumentException('You must provide either a FromStage or FromVersion argument');
            }
            if (!$record) {
                throw new InvalidArgumentException("Record {$id} not found");
            }

            $member = UserContextProvider::get($context);
            // Permission check object
            $can = $to === Versioned::LIVE
                ? $record->canPublish($member)
                : $record->canEdit($member);
            if (!$can) {
                throw new InvalidArgumentException(sprintf(
                    'Copying %s from %s to %s is not allowed',
                    $this->getTypeName(),
                    $from,
                    $to
                ));
            }

            /** @var DataObject|Versioned $record */
            $record->copyVersionToStage($from, $to);
            return $record;
        };
    }

    /**
     * @param array $context
     * @return Closure
     */
    public static function resolvePublishOperation(array $context)
    {
        $action = $context['action'] ?? null;
        $dataClass = $context['dataClass'] ?? null;
        $allowedActions = [
            AbstractPublishOperationCreator::ACTION_PUBLISH,
            AbstractPublishOperationCreator::ACTION_UNPUBLISH,
        ];
        if (!in_array($action, $allowedActions ?? [])) {
            throw new InvalidArgumentException(sprintf(
                'Invalid publish action: %s',
                $action
            ));
        }

        $isPublish = $action === AbstractPublishOperationCreator::ACTION_PUBLISH;

        return function ($obj, array $args, array $context, ResolveInfo $info) use ($isPublish, $dataClass) {
            if (!$dataClass) {
                return;
            }
            $stage = $isPublish ? Versioned::DRAFT : Versioned::LIVE;
            $obj = Versioned::get_by_stage($dataClass, $stage)
                ->byID($args['id']);
            if (!$obj) {
                throw new Exception(sprintf(
                    '%s with ID %s not found',
                    $dataClass,
                    $args['id']
                ));
            }
            $permissionMethod = $isPublish ? 'canPublish' : 'canUnpublish';
            $member = UserContextProvider::get($context);
            if (!$obj->$permissionMethod($member)) {
                throw new Exception(sprintf(
                    'Not allowed to change published state of this %s',
                    $dataClass
                ));
            }

            try {
                DB::get_conn()->withTransaction(function () use ($obj, $isPublish) {
                    if ($isPublish) {
                        $obj->publishRecursive();
                    } else {
                        $obj->doUnpublish();
                    }
                });
            } catch (ValidationException $e) {
                throw new Exception(
                    'Could not changed published state of %s. Got error: %s',
                    $dataClass,
                    $e->getMessage()
                );
            }
            return $obj;
        };
    }

    /**
     * @param array $context
     * @return Closure
     * @see RollbackCreator
     */
    public static function resolveRollback(array $context)
    {
        $dataClass = $context['dataClass'] ?? null;
        return function ($obj, array $args, array $context, ResolveInfo $info) use ($dataClass) {
            if (!$dataClass) {
                return;
            }
            // Get the args
            $id = $args['id'];
            $rollbackVersion = $args['toVersion'];

            // Pull the latest version of the record
            /** @var Versioned|DataObject $record */
            $record = Versioned::get_latest_version($dataClass, $id);

            // Assert permission
            $user = UserContextProvider::get($context);
            if (!$record->canEdit($user)) {
                throw new InvalidArgumentException('Current user does not have permission to roll back this resource');
            }

            // Perform the rollback
            $record = $record->rollbackRecursive($rollbackVersion);

            return $record;
        };
    }
}
