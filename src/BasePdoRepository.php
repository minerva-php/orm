<?php

namespace Minerva\Orm;

use Exception;
use PDO;
use Doctrine\Common\Inflector\Inflector;
use Xuid\Xuid;

abstract class BasePdoRepository implements RepositoryInterface
{
    private $tableName;
    protected $pdo;
    protected $filter;
    protected $modelClassName;

    public function __construct(PDO $pdo, $tableName = null)
    {
        if (is_null($tableName)) {
            $tableName = get_class($this);
            $tableName = explode('\\', $tableName);
            $tableName = end($tableName);
            $tableName = substr($tableName, strlen('Pdo'), -strlen('Repository'));

            $tableName = Inflector::tableize($tableName);
        }

        $this->tableName = $tableName;
        $this->pdo = $pdo;
    }

    public function createEntity()
    {
        $name = $this->getModelClassName();

        return $name::createNew();
    }

    public function getModelClassName()
    {
        if (!$this->modelClassName) {
            // If the modelClassName is not explicitly defined in the subclass,
            // make an educated guess based on the repository class name
            $name = get_class($this);
            $name = str_replace('\\Repository\\', '\\Model\\', $name);

            $name = str_replace('\\Pdo', '\\', $name);
            $name = substr($name, 0, -strlen('Repository'));
            $this->modelClassName = $name;
        }

        return $this->modelClassName;
    }

    public function setPdo(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function setFilter($filter)
    {
        // Only add filters for properties that are present on this repository's model class.
        $res = [];
        foreach ($filter as $key => $value) {
            if (property_exists($this->getModelClassName(), $key)) {
                $res[$key] = $value;
            }
        }
        if (count($res) > 0) {
            $this->filter = $res;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    /**
     * {@inheritdoc}
     */
    public function find($id)
    {
        $rows = $this->fetchRows(array('id' => $id));
        if (0 == count($rows)) {
            throw new Exception(sprintf(
                "Entity '%s' with id $id not found",
                $this->getTable(),
                $id
            ));
        }

        return $this->rowToObject($rows[0]);
    }

    /**
     * {@inheritdoc}
     */
    public function findOrCreate($id)
    {
        $rows = $this->fetchRows(array('id' => $id));
        if (0 == count($rows)) {
            $entity = $this->createEntity();
            if ($this->filter) {
                foreach ($this->filter as $key => $value) {
                    if (property_exists($entity, $key)) {
                        $propertyName = Inflector::camelize($key);
                        $setter = sprintf('set%s', ucfirst($propertyName));
                        $entity->$setter($value);
                    }
                }
            }
        } else {
            $entity = $this->rowToObject($rows[0]);
        }

        return $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function findAll()
    {
        $rows = $this->fetchRows([]);

        return $this->rowsToObjects($rows);
    }

    public function findById(array $id)
    {
        if (sizeof($id) < 1) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, sizeof($id), '?'));

        $sql = "SELECT *
                FROM `{$this->getTableName()}`
                WHERE `id` IN ({$placeholders})
                ORDER BY `id` DESC;";

        $statement = $this->pdo->prepare($sql);
        $statement->execute($id);

        return $this->rowsToObjects($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    protected function fetchRows($where, $limit = null)
    {
        if ($this->filter) {
            $where = array_merge($where, $this->filter);
        }
        $sql = sprintf('SELECT * FROM `%s` WHERE 1', $this->getTableName());

        if (count($where) > 0) {
            $sql .= sprintf(' AND %s', $this->buildKeyValuePairs($where, ' and '));
        }

        if ($this->getSoftDeleteColumnName()) {
            $sql .= sprintf(' AND %s is null', $this->getSoftDeleteColumnName());
        }

        if ($limit > 0) {
            $sql .= sprintf(' LIMIT %d', $limit);
        }

        $statement = $this->pdo->prepare($sql);
        $where = $this->flattenValues($where);
        $statement->execute($where);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function persist($entity)
    {
        // TODO: SanityCheck: make sure entity is an instance of this->className
        // XUID
        if (property_exists($entity, 'xuid')) {
            $xuid = $entity->getXuid();
            if (!$xuid) {
                $xuid = new Xuid();
                $entity->setXuid($xuid->getXuid());
            }
        }
        $fields = $this->objectToArray($entity);
        unset($fields['id']);

        if ($entity->getId()) {
            $where = array(
                'id' => $entity->getId(),
            );
            $sql = $this->buildUpdateSql($fields, $where);
            $statement = $this->pdo->prepare($sql);
            $res = $statement->execute($this->prepareFieldsValues($fields + $where));
        } else {
            $sql = $this->buildInsertSql($fields);
            $this->pdo->prepare($sql)->execute($this->prepareFieldsValues($fields));
            $entity->setId($this->pdo->lastInsertId());
        }

        return true;
    }

    /**
     * @param array $fields
     *
     * @return array
     */
    protected function prepareFieldsValues($fields)
    {
        return array_map(function ($value) {
            if ($value instanceof \DateTime) {
                return $value->format('Y-m-d H:i:s');
            }

            return $value;
        }, $fields);
    }

    protected function getSoftDeleteColumnName()
    {
        if (property_exists($this->getModelClassName(), 'deleted_at')) {
            return 'deleted_at';
        }

        return null; // no soft-delete field
    }

    /**
     * {@inheritdoc}
     */
    public function remove($entity)
    {
        // TODO: SanityCheck: make sure entity is an instance of this->className
        if ($this->getSoftDeleteColumnName()) {
            // Yes, use soft-delete
            $propertyName = Inflector::camelize($this->getSoftDeleteColumnName());
            $setter = sprintf('set%s', ucfirst($propertyName));
            $entity->$setter(date('Y-m-d H:i:s'));
            $this->persist($entity);
        } else {
            // No, use hard-delete
            $statement = $this->pdo->prepare(sprintf(
                'DELETE FROM `%s` WHERE id=:id',
                $this->getTableName()
            ));
            $statement->execute(array('id' => $entity->getId()));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function truncate()
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->pdo->exec(sprintf(
            'TRUNCATE `%s`',
            $this->getTableName()
        ));
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Convert array to entity.
     *
     * @param array $row
     *
     * @return ModelInterface
     */
    protected function rowToObject($row)
    {
        if ('array' == $this->returnDataType) {
            return $row;
        }

        if ($row) {
            $object = $this->createEntity();
            $this->loadObjectFromArray($object, $row);

            return $object;
        }

        return;
    }

    /**
     * Convert array to array of entities.
     *
     * @param array $rows
     *
     * @return ModelInterface[]
     */
    protected function rowsToObjects($rows)
    {
        return array_map(function ($row) {
            return $this->rowToObject($row);
        }, $rows);
    }

    /**
     * @return string
     */
    protected function buildUpdateSql($fields, $where)
    {
        return sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $this->getTableName(),
            $this->buildKeyValuePairs($fields, ',', false),
            $this->buildKeyValuePairs($where, ' and ', false)
        );
    }

    /**
     * @param array $where
     *
     * @return string
     */
    protected function buildKeyValuePairs($where, $delimiter, $isNullPatch = true)
    {
        return implode($delimiter, array_map(function ($field, $value) use ($isNullPatch) {
            if (is_array($value)) {
                // Transform [field=>[0=>a,1=>b,2=>c]] to 'field in (:field_0, :field_1, :field_2)'
                return sprintf('`%s` in (%s)', $field, implode(', ', array_map(function ($index) use ($field) {
                    return sprintf(':%s_%s', $field, $index);
                }, array_keys($value))));
            }

            // return sprintf('`%s`=:%s', $field, $field);
            // IS NULL
            if ($isNullPatch && null === $value) {
                return sprintf('`%s` IS NULL', $field);
            } else {
                return sprintf('`%s`=:%s', $field, $field);
            }
        }, array_keys($where), $where));
    }

    protected function flattenValues($fields)
    {
        $result = array();
        array_walk($fields, function ($value, $field) use (&$result) {
            // IS NULL
            if (null === $value) {
                return;
            }

            if (is_array($value)) {
                // Transform [field=>[0=>a,1=>b,2=>c]] to [field_0=>a, field_1=>b, field_2=>c)
                foreach ($value as $index => $index_value) {
                    $index_key = sprintf('%s_%s', $field, $index);
                    $result[$index_key] = $index_value;
                }
            } else {
                $result[$field] = $value;
            }
        });

        return $result;
    }

    /**
     * @return string
     */
    protected function buildInsertSql($fields)
    {
        $fields_names = array_keys($fields);

        return sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->getTableName(),
            '`'.implode('`, `', $fields_names).'`',
            ':'.implode(', :', $fields_names)
        );
    }

    /**
     * Return human-readable representation
     * of where array keys and values.
     * Used in Exceptions.
     *
     * @param array $where
     *
     * @return string
     */
    protected function describeWhereFields($where)
    {
        return implode(', ', array_map(function ($v, $k) {
            return sprintf("%s='%s'", $k, $v);
        }, $where, array_keys($where)));
    }

    protected $returnDataType = 'object';

    public function getReturnDataType()
    {
        return $this->returnDataType;
    }

    public function setReturnDataType($returnDataType)
    {
        $this->returnDataType = $returnDataType;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    private function objectToArray($object)
    {
        $fields = (array) $object;

        $result = array();
        foreach ($fields as $key => $value) {
            if (!strpos($key, '\\')) {
                $key = trim(str_replace('*', '', $key));
                $propertyName = Inflector::camelize($key);
                $key = Inflector::tableize($key); // resolve field  casing issue//
                $getter = sprintf('get%s', ucfirst($propertyName));
                $result[$key] = $object->$getter($value);
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    private function loadObjectFromArray($object, $data, $allowed_keys = null)
    {
        if (is_null($allowed_keys)) {
            $allowed_keys = array_keys((array) $data);
        }

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed_keys)) {
                continue;
            }

            $propertyName = Inflector::camelize($key);
            $setter = sprintf('set%s', ucfirst($propertyName));
            $object->$setter($value);
        }

        return $object;
    }
}
