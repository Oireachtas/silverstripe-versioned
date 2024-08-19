<?php

namespace SilverStripe\Versioned\GraphQL\Operations;

use SilverStripe\GraphQL\Schema\Interfaces\OperationCreator;

// GraphQL dependency is optional in versioned,
// and the following implementation relies on existence of this class (in GraphQL v4)
if (!interface_exists(OperationCreator::class)) {
    return;
}

/**
 * Scaffolds a generic update operation for DataObjects.
 *
 * @deprecated 5.3.0 Will be moved to the silverstripe/graphql module
 */
class UnpublishCreator extends AbstractPublishOperationCreator
{
    public function __construct()
    {
        Deprecation::withNoReplacement(function () {
            Deprecation::notice('5.3.0', 'Will be moved to the silverstripe/graphql module', Deprecation::SCOPE_CLASS);
        });
    }

    /**
     * @param string $typeName
     * @return string
     */
    protected function createOperationName(string $typeName): string
    {
        return 'unpublish' . ucfirst($typeName ?? '');
    }

    /**
     * @return string
     */
    protected function getAction(): string
    {
        return AbstractPublishOperationCreator::ACTION_UNPUBLISH;
    }
}
