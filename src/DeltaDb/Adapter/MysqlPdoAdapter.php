<?php
/**
 * User: Vasiliy Shvakin (orbisnull) zen4dev@gmail.com
 */

namespace DeltaDb\Adapter;


use DeltaDb\Adapter\WhereParams\Between;
use DeltaUtils\StringUtils;

class MysqlPdoAdapter extends AbstractAdapter
{
    protected $isTransaction = 0;

    public function connect($dsn = null, $params = [])
    {
        if (!is_null($dsn)) {
            $this->setDsn($dsn);
        }
        if (!empty($params)) {
            $this->setParams($params);
        }
        $params = $this->getParams();
        $user = isset($params["user"]) ? $params["user"] : "root";
        $pass = isset($params["password"]) ? $params["password"] : "root";
        $options = isset($params["options"]) ? $params["options"] : [\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'];
        $connection = new \PDO($this->getDsn(), $user, $pass, $options);
        $this->setConnection($connection);
    }

    /**
     * @return \PDO
     */
    public function getConnection()
    {
        return parent::getConnection(); // TODO: Change the autogenerated stub
    }

    public function query($query)
    {
        if (func_num_args() === 1) {
            $params = [];
        } else {
            $params = func_get_args();
            array_shift($params);
        }
        $result = $this->queryParams($query, $params);

        return $result;
    }


    public function queryParams($query, $params)
    {
        $connection = $this->getConnection();
        $pQuery = $connection->prepare($query);
        $count = count($params);
        $params = array_values($params);
        for($i=1; $i<=$count; $i++) {
            $pQuery->bindValue($i, $params[$i-1]);
        }
        $result = $pQuery->execute();
        return $pQuery;

    }

    public function select($query)
    {
        /** @var \PDOStatement $result */
        $result = call_user_func_array([$this, 'query'], func_get_args());
        if (!$result) {
            return [];
        }

        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);
        if(!is_array($rows)) {
            return [];
        }
        return $rows;
    }

    public function selectRow($query)
    {
        /** @var \PDOStatement $result */
        $result = call_user_func_array([$this, 'query'], func_get_args());
        return $result->fetch(\PDO::FETCH_ASSOC);
    }

    public function selectCol($query)
    {
        /** @var \PDOStatement $result */
        $result = call_user_func_array([$this, 'query'], func_get_args());
        return $result->fetchAll(\PDO::FETCH_COLUMN, 0);
    }

    public function selectCell($query)
    {
        /** @var \PDOStatement $result */
        $result = call_user_func_array([$this, 'query'], func_get_args());
        return $result->fetchColumn(0);
    }

    /**
     * @param int $isTransaction
     */
    protected function setIsTransaction($isTransaction)
    {
        $this->isTransaction = $isTransaction;
    }

    /**
     * @return int
     */
    public function IsTransaction()
    {
        return $this->isTransaction;
    }

    public function begin()
    {
        if ($this->isTransaction()) {
            throw new \LogicException('Transaction already started');
        }
        $this->setIsTransaction(true);
        $this->getConnection()->beginTransaction();
    }

    public function commit()
    {
        if ($this->isTransaction()) {
            throw new \LogicException('Transaction not started');
        }
        $this->getConnection()->commit();
        $this->setIsTransaction(false);
    }

    public function rollBack()
    {
        if ($this->isTransaction()) {
            throw new \LogicException('Transaction not started');
        }
        $this->getConnection()->rollBack();
        $this->setIsTransaction(false);
    }

    public function insert($table, $fields, $idName = null, $rawFields = null)
    {
        $rawFields = array_flip((array)$rawFields);
        $fieldsList = array_keys($fields);
        $fieldsNames = $fieldsList;
        foreach($fieldsList as $key=>$name) {
            $fieldsList[$key] = $this->escapeIdentifier($name);
        }
        $fieldsList = implode(', ', $fieldsList);
        $num = 0;
        $fieldsQuery = [];
        foreach($fieldsNames as $fieldName) {
            if (!isset($rawFields[$fieldName])) {
                $num++;
                $fieldsQuery[] = '?';
            } else {
                $fieldsQuery[] = $fields[$fieldName];
                unset($fields[$fieldName]);
            }
        }
        $fieldsQuery = implode(', ', $fieldsQuery);

        $query = "insert into `{$table}` ({$fieldsList}) values ({$fieldsQuery})";
        $result = $this->queryParams($query, $fields);
        if ($result === false) {
            return false;
        }
        /*if (is_null($idName)) {
            return (pg_affected_rows($result) >0);
        } else {
            return pg_fetch_result($result, 0, 0);
        }*/
    }

    public function escapeIdentifier($field)
    {
        if (strpos($field, ".") === false) {
            return '`' . $field . '`';
        }
        $fieldArr = explode(".", $field);
        return $fieldArr[0] . "." . '`' . $fieldArr[1] . '`';
    }

    public function getWhere(array $criteria, $num = 0)
    {
        $where = [];
        foreach ($criteria as $field => $value) {
            if (is_object($value)) {
                $class = StringUtils::cutClassName(get_class($value));
                switch($class) {
                    case "Between":
                        /** @var Between $value*/
                        $num++;
                        $num2 = $num + 1;
                        $where[] = $this->escapeIdentifier($field) . " between ? and ?";
                        $num = $num2;
                        break;
                    default :
                        throw new \InvalidArgumentException("where class $class not implement");
                }

            } elseif (is_array($value)) {
                $inParams = [];
                foreach($value as $valueItem){
                    $num++;
                    $inParams[] = "\${$num}";
                }
                $inParams = implode(', ', $inParams);
                $where[] = $this->escapeIdentifier($field) . " in ({$inParams})";
            } else {
                $num++;
                $where[] = $this->escapeIdentifier($field) . '=?';
            }
        }
        $where = implode(' and ', $where);
        if (!empty($where)){
            $where = ' where ' . $where;
        }
        return $where;
    }

    public function getWhereParams(array $criteria)
    {
        $whereParams = [];
        foreach ($criteria as $field => $value) {
            if (is_object($value)) {
                $class = StringUtils::cutClassName(get_class($value));
                switch ($class) {
                    case "Between":
                        /** @var Between $value */
                        $whereParams[] = $value->getStart();
                        $whereParams[] = $value->getEnd();
                        break;
                    default :
                        throw new \InvalidArgumentException("where class $class not implement");
                }

            } elseif (is_array($value)) {
                foreach ($value as $valueItem) {
                    $whereParams[] = $valueItem;
                }
            } else {
                $whereParams[] = $value;
            }
        }
        return $whereParams;
    }

    public function getOrderBy($orderBy)
    {
        $orderStr = "";
        if (!is_null($orderBy)) {
            if (is_array($orderBy)) {
                $orderField = $orderBy[0];
                $orderDirect = $orderBy[1];
                $orderStr = " order by {$orderField} {$orderDirect}";
            } else {
                $orderStr = " order by {$orderBy}";
            }
        }
        return $orderStr;
    }

    public function update($table, $fields, array $criteria, $rawFields = null)
    {
        $query = "update {$table}";
        if (empty($criteria) || empty($fields)) {
            return false;
        }
        $rawFields = array_flip((array)$rawFields);
        $fieldsNames = array_keys($fields);
        $num = 0;
        $fieldsQuery = [];
        foreach($fieldsNames as $fieldName) {
            if (!isset($rawFields[$fieldName])) {
                $num++;
                $fieldsQuery[] = $this->escapeIdentifier($fieldName) . '=?';
            } else {
                $fieldsQuery[] = $this->escapeIdentifier($fieldName) . '=' . $fields[$fieldName];
                unset($fields[$fieldName]);
            }
        }
        $fieldsQuery = ' set ' . implode(', ', $fieldsQuery);
        $query .= $fieldsQuery;
        $query .= $this->getWhere($criteria, $num);
        $fieldsValues = array_values($fields);
        $whereParams = $this->getWhereParams($criteria);
        $queryParam = array_merge($fieldsValues, $whereParams);
        $result = $this->queryParams($query, $queryParam);
//        return ($result === false) ? false : pg_affected_rows($result);
    }

    public function delete($table, array $criteria)
    {
        $query = "delete from `{$table}`";
        if (empty($criteria)) {
            return false;
        }
        $query .= $this->getWhere($criteria);
        $whereParams = $this->getWhereParams($criteria);
        $result = $this->queryParams($query, $whereParams);

//        return ($result === false) ? false : pg_affected_rows($result);
    }

    public function getLimit($limit = null, $offset = null)
    {
        $sql = "";
        if (!is_null($limit)) {
            $limit = (integer)$limit;
            $sql .= " limit {$limit}";
        }
        if (!is_null($offset)) {
            $offset = (integer) $offset;
            $sql .= " offset {$offset}";
        }
        return $sql;
    }

    public function selectBy($table, array $criteria = [], $limit = null, $offset = null, $orderBy = null)
    {
        $query = "select * from `{$table}`";
        $limitSql = $this->getLimit($limit, $offset);
        if (empty($criteria)) {
            $query .= $limitSql;
            return $this->select($query);
        }
        $orderStr = "";
        $query .= $this->getWhere($criteria);
        $query .= $orderStr;
        $query .= $limitSql;
        $whereParams = $this->getWhereParams($criteria);
        array_unshift($whereParams, $query);
        return call_user_func_array([$this, 'select'],  $whereParams);
    }

    public function count($table, array $criteria = [])
    {
        $query = "select count(*) from `{$table}`";
        if (empty($criteria)) {
            $result = $this->selectCell($query);
        } else {
            $query .= $this->getWhere($criteria);
            $whereParams = $this->getWhereParams($criteria);
            array_unshift($whereParams, $query);
            $result = call_user_func_array([$this, 'selectCell'], $whereParams);
        }
        return (integer)$result;
    }

} 