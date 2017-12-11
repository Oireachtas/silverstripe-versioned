<?php


namespace SilverStripe\Versioned;

use LogicException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * Provides owns / owned_by and recursive publishing API for all objects.
 * This extension is added to DataObject by default
 */
class RecursivePublishable extends DataExtension
{
    /**
     * List of relationships on this object that are "owned" by this object.
     * Owership in the context of versioned objects is a relationship where
     * the publishing of owning objects requires the publishing of owned objects.
     *
     * E.g. A page owns a set of banners, as in order for the page to be published, all
     * banners on this page must also be published for it to be visible.
     *
     * Typically any object and its owned objects should be visible in the same edit view.
     * E.g. a page and {@see GridField} of banners.
     *
     * Page hierarchy is typically not considered an ownership relationship.
     *
     * Ownership is recursive; If A owns B and B owns C then A owns C.
     *
     * @config
     * @var array List of has_many or many_many relationships owned by this object.
     */
    private static $owns = [];

    /**
     * Opposing relationship to owns config; Represents the objects which
     * own the current object.
     *
     * @var array
     */
    private static $owned_by = [];

    /**
     * Publish this object and all owned objects to Live
     *
     * @return bool
     */
    public function publishRecursive()
    {
        // Create a new changeset for this item and publish it
        $changeset = ChangeSet::create();
        $changeset->IsInferred = true;
        $changeset->Name = _t(
            __CLASS__ . '.INFERRED_TITLE',
            "Generated by publish of '{title}' at {created}",
            [
                'title' => $this->owner->Title,
                'created' => DBDatetime::now()->Nice()
            ]
        );
        $changeset->write();
        $changeset->addObject($this->owner);
        return $changeset->publish();
    }

    /**
     * Remove this item from any changesets
     *
     * @return bool
     */
    public function deleteFromChangeSets()
    {
        $changeSetIDs = [];

        // Remove all ChangeSetItems matching this record
        /** @var ChangeSetItem $changeSetItem */
        foreach (ChangeSetItem::get_for_object($this->owner) as $changeSetItem) {
            $changeSetIDs[$changeSetItem->ChangeSetID] = $changeSetItem->ChangeSetID;
            $changeSetItem->delete();
        }

        // Sync all affected changesets
        if ($changeSetIDs) {
            /** @var ChangeSet $changeSet */
            foreach (ChangeSet::get()->byIDs($changeSetIDs) as $changeSet) {
                $changeSet->sync();
            }
        }
        return true;
    }

    /**
     * Find all objects owned by the current object.
     * Note that objects will only be searched in the same stage as the given record.
     *
     * @param bool $recursive True if recursive
     * @param ArrayList $list Optional list to add items to
     * @return ArrayList list of objects
     */
    public function findOwned($recursive = true, $list = null)
    {
        // Find objects in these relationships
        return $this->owner->findRelatedObjects('owns', $recursive, $list);
    }

    /**
     * Find objects which own this object.
     * Note that objects will only be searched in the same stage as the given record.
     *
     * @param bool $recursive True if recursive
     * @param ArrayList $list Optional list to add items to
     * @return ArrayList list of objects
     */
    public function findOwners($recursive = true, $list = null)
    {
        if (!$list) {
            $list = new ArrayList();
        }

        // Build reverse lookup for ownership
        // @todo - Cache this more intelligently
        $rules = $this->lookupReverseOwners();

        // Hand off to recursive method
        return $this->findOwnersRecursive($recursive, $list, $rules);
    }

    /**
     * Find objects which own this object.
     * Note that objects will only be searched in the same stage as the given record.
     *
     * @param bool $recursive True if recursive
     * @param ArrayList $list List to add items to
     * @param array $lookup List of reverse lookup rules for owned objects
     * @return ArrayList list of objects
     */
    public function findOwnersRecursive($recursive, $list, $lookup)
    {
        // First pass: find objects that are explicitly owned_by (e.g. custom relationships)
        /** @var DataObject $owner */
        $owner = $this->owner;
        $owners = $owner->findRelatedObjects('owned_by', false);

        // Second pass: Find owners via reverse lookup list
        foreach ($lookup as $ownedClass => $classLookups) {
            // Skip owners of other objects
            if (!is_a($this->owner, $ownedClass)) {
                continue;
            }
            foreach ($classLookups as $classLookup) {
                // Merge new owners into this object's owners
                $ownerClass = $classLookup['class'];
                $ownerRelation = $classLookup['relation'];
                $result = $this->owner->inferReciprocalComponent($ownerClass, $ownerRelation);
                $owner->mergeRelatedObjects($owners, $result);
            }
        }

        // Merge all objects into the main list
        $newItems = $owner->mergeRelatedObjects($list, $owners);

        // If recursing, iterate over all newly added items
        if ($recursive) {
            foreach ($newItems as $item) {
                /** @var RecursivePublishable|DataObject $item */
                $item->findOwnersRecursive(true, $list, $lookup);
            }
        }

        return $list;
    }

    /**
     * Find a list of classes, each of which with a list of methods to invoke
     * to lookup owners.
     *
     * @return array
     */
    protected function lookupReverseOwners()
    {
        // Find all classes with 'owns' config
        $lookup = [];
        foreach (ClassInfo::subclassesFor(DataObject::class) as $class) {
            // Ensure this class is versioned
            if (!DataObject::has_extension($class, static::class)) {
                continue;
            }

            // Check owned objects for this class
            $owns = Config::inst()->get($class, 'owns', Config::UNINHERITED);
            if (empty($owns)) {
                continue;
            }

            $instance = DataObject::singleton($class);
            foreach ($owns as $owned) {
                // Find owned class
                $ownedClass = $instance->getRelationClass($owned);
                // Skip custom methods that don't have db relationsm
                if (!$ownedClass) {
                    continue;
                }
                if ($ownedClass === DataObject::class) {
                    throw new LogicException(sprintf(
                        "Relation %s on class %s cannot be owned as it is polymorphic",
                        $owned,
                        $class
                    ));
                }

                // Add lookup for owned class
                if (!isset($lookup[$ownedClass])) {
                    $lookup[$ownedClass] = [];
                }
                $lookup[$ownedClass][] = [
                    'class' => $class,
                    'relation' => $owned
                ];
            }
        }
        return $lookup;
    }

    /**
     * Set foreign keys of has_many objects to 0 where those objects were
     * disowned as a result of a partial publish / unpublish.
     * I.e. this object and its owned objects were recently written to $targetStage,
     * but deleted objects were not.
     *
     * Note that this operation does not create any new Versions
     *
     * @param string $sourceStage Objects in this stage will not be unlinked.
     * @param string $targetStage Objects which exist in this stage but not $sourceStage
     * will be unlinked.
     */
    public function unlinkDisownedObjects($sourceStage, $targetStage)
    {
        $owner = $this->owner;

        // after publishing, objects which used to be owned need to be
        // dis-connected from this object (set ForeignKeyID = 0)
        $owns = $owner->config()->get('owns');
        $hasMany = $owner->config()->get('has_many');
        if (empty($owns) || empty($hasMany)) {
            return;
        }

        $schema = DataObject::getSchema();
        $ownedHasMany = array_intersect($owns, array_keys($hasMany));
        foreach ($ownedHasMany as $relationship) {
            // Check the owned object is actually versioned and staged
            $joinClass = $schema->hasManyComponent(get_class($owner), $relationship);
            $joinInstance = DataObject::singleton($joinClass);

            /** @var Versioned $versioned */
            $versioned = $joinInstance->getExtensionInstance(Versioned::class);
            if (!$versioned || !$versioned->hasStages()) {
                continue;
            }

            // Find metadata on relationship
            $joinField = $schema->getRemoteJoinField(get_class($owner), $relationship, 'has_many', $polymorphic);
            $idField = $polymorphic ? "{$joinField}ID" : $joinField;
            $joinTable = DataObject::getSchema()->tableForField($joinClass, $idField);

            // Generate update query which will unlink disowned objects
            $targetTable = $versioned->stageTable($joinTable, $targetStage);
            $disowned = new SQLUpdate("\"{$targetTable}\"");
            $disowned->assign("\"{$idField}\"", 0);
            $disowned->addWhere([
                "\"{$targetTable}\".\"{$idField}\"" => $owner->ID
            ]);

            // Build exclusion list (items to owned objects we need to keep)
            $sourceTable = $versioned->stageTable($joinTable, $sourceStage);
            $owned = new SQLSelect("\"{$sourceTable}\".\"ID\"", "\"{$sourceTable}\"");
            $owned->addWhere([
                "\"{$sourceTable}\".\"{$idField}\"" => $owner->ID
            ]);

            // Apply class condition if querying on polymorphic has_one
            if ($polymorphic) {
                $disowned->assign("\"{$joinField}Class\"", null);
                $disowned->addWhere([
                    "\"{$targetTable}\".\"{$joinField}Class\"" => get_class($owner)
                ]);
                $owned->addWhere([
                    "\"{$sourceTable}\".\"{$joinField}Class\"" => get_class($owner)
                ]);
            }

            // Merge queries and perform unlink
            $ownedSQL = $owned->sql($ownedParams);
            $disowned->addWhere([
                "\"{$targetTable}\".\"ID\" NOT IN ({$ownedSQL})" => $ownedParams
            ]);

            $owner->extend('updateDisownershipQuery', $disowned, $sourceStage, $targetStage, $relationship);

            $disowned->execute();
        }
    }
}
