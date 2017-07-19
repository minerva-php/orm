<?php

namespace Minerva\Orm;

interface RepositoryInterface
{
    /**
     * Find a set of objects by supplying a list of object IDs.
     *
     * @param array $id
     *
     * @return array
     */
    public function findByIds($ids);
}
