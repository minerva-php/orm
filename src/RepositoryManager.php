<?php

namespace Minerva\Orm;

use Minerva\Orm\Exception\RepositoryNotFoundException;
use Minerva\Orm\RepositoryInterface;

class RepositoryManager
{
    protected $repositories = [];
    
    public function getRepositories()
    {
        return $this->repositories;
    }
    
    public function addRepository(RepositoryInterface $repository)
    {
        $className = $repository->getModelClassName();
        $this->repositories[$className] = $repository;
    }
    
    public function hasRepository($className)
    {
        return isset($this->repositories[$className]);
    }
    
    public function getRepository($className)
    {
        if (!$this->hasRepository($className)) {
            throw new RepositoryNotFoundException($className);
        }
        return $this->repositories[$className];
    }
    
    public function getRepositoryByTableName($tableName)
    {
        foreach ($this->repositories as $repository) {
            if ($repository->getTableName() == $tableName) {
                return $repository;
            }
        }
        throw new RepositoryNotFoundException($className);
    }
    
    public function autoloadPdoRepositories($path, $ns, $pdo)
    {
        if (!file_exists($path)) {
            return;
        }
        
        foreach (glob($path.'/Pdo*Repository.php') as $filename) {
            $className = $ns . '\\' . basename($filename, '.php');
            // only load classes that implement correct interface
            if (in_array('Minerva\\Orm\\RepositoryInterface', class_implements($className))) {
                $repo = new $className($pdo);
                $this->addRepository($repo);
            }
        }
    }
}
