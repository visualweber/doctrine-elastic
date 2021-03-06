<?php

namespace DoctrineElastic;

use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMInvalidArgumentException;
use DoctrineElastic\Hydrate\SimpleEntityHydrator;
use DoctrineElastic\Persister\ElasticEntityPersister;
use InvalidArgumentException;

/**
 * Here is a Elastic adaptation for UoW of Doctrine.
 * There is many simplifications, just like entity states, persist, remove, delete, commit actions,
 * and much more.
 *
 * @author Andsalves <ands.alves.nunes@gmail.com>
 */
class ElasticUnitOfWork {

    /**
     * Entity has been persisted and now is managed by EntityManager
     */
    const STATE_MANAGED = 1;

    /**
     * Entity has been instantiated, and is not managed by EntityManager (yet)
     */
    const STATE_NEW = 2;

    /**
     * Entity has value(s) for identity field(s) and exists in elastic, therefore,
     * is not managed by EntityManager (yet)
     */
    const STATE_DETACHED = 3;

    /**
     * Entity was deleted by remove method, and will be removed on commit
     */
    const STATE_DELETED = 4;

    /* @var ElasticEntityManager */
    private $em;

    /** @var string[] */
    private $entitiesCommitOrder = [];

    /** @var array */
    protected $entityDeletions = [];

    /** @var array */
    protected $entityInsertions = [];

    /** @var array */
    protected $entityUpdates = [];

    private $hydrator;

    public function __construct(ElasticEntityManager $em) {
        $this->em = $em;
        $this->hydrator = new SimpleEntityHydrator();
    }

    /**
     * @param string $entityName
     * @return ElasticEntityPersister
     */
    public function getEntityPersister($entityName) {
        return new ElasticEntityPersister($this->em, $entityName);
    }

    /**
     * @param object $entity
     */
    public function persist($entity) {
        $oid = spl_object_hash($entity);

        switch ($this->getEntityState($entity)) {
            default:
                if (isset($this->entityInsertions[$oid])) {
                    unset($this->entityInsertions[$oid]);
                }
                if (isset($this->entityUpdates[$oid])) {
                    unset($this->entityUpdates[$oid]);
                }

                $this->scheduleForInsert($entity);
                break;
            case self::STATE_DETACHED:
                $this->scheduleForUpdate($entity);
                break;
        }

        $this->entitiesCommitOrder[] = get_class($entity);
    }

    /**
     * @param object $entity
     * @return int
     */
    public function getEntityState($entity) {
        if ($this->isEntityScheduled($entity)) {
            if ($this->isScheduledForDelete($entity)) {
                return self::STATE_DELETED;
            } else {
                return self::STATE_MANAGED;
            }
        }

        $persister = $this->getEntityPersister(get_class($entity));

        if (method_exists($entity, 'get_id')) {
            $_id = $entity->get_id();
        } else {
            $_id = $entity->_id;
        }

        if (boolval($_id)) {
            $element = $persister->loadById(['_id' => $_id]);

            if ($element) {
                return self::STATE_DETACHED;
            }
        }

        return self::STATE_NEW;
    }

    /**
     * @param object $entity
     */
    public function scheduleForInsert($entity) {
        if ($this->isScheduledForUpdate($entity)) {
            throw new InvalidArgumentException('Dirty entity can not be scheduled for insertion.');
        }

        if ($this->isScheduledForDelete($entity)) {
            throw ORMInvalidArgumentException::scheduleInsertForRemovedEntity($entity);
        }

        if ($this->isScheduledForInsert($entity)) {
            throw ORMInvalidArgumentException::scheduleInsertTwice($entity);
        }

        $this->entityInsertions[spl_object_hash($entity)] = $entity;
    }

    public function isScheduledForInsert($entity) {
        return isset($this->entityInsertions[spl_object_hash($entity)]);
    }

    public function isScheduledForUpdate($entity) {
        return isset($this->entityUpdates[spl_object_hash($entity)]);
    }

    public function isScheduledForDelete($entity) {
        return isset($this->entityDeletions[spl_object_hash($entity)]);
    }

    public function isEntityScheduled($entity) {
        $oid = spl_object_hash($entity);

        return isset($this->entityInsertions[$oid])
        || isset($this->entityUpdates[$oid])
        || isset($this->entityDeletions[$oid]);
    }

    public function scheduleForUpdate($entity) {
        $oid = spl_object_hash($entity);

        if (isset($this->entityDeletions[$oid])) {
            throw ORMInvalidArgumentException::entityIsRemoved($entity, "schedule for update");
        }

        if (!isset($this->entityUpdates[$oid]) && !isset($this->entityInsertions[$oid])) {
            $this->entityUpdates[$oid] = $entity;
        }
    }

    public function scheduleForDelete($entity) {
        $oid = spl_object_hash($entity);

        if (isset($this->entityInsertions[$oid])) {
            unset($this->entityInsertions[$oid]);
        }

        if (isset($this->entityUpdates[$oid])) {
            unset($this->entityUpdates[$oid]);
        }

        if (!isset($this->entityDeletions[$oid])) {
            $this->entityDeletions[$oid] = $entity;
        }
    }

    /**
     * @param null|object|array $entity
     * @return void
     * @throws \Exception
     */
    public function commit($entity = null) {
        if ($this->em->getEventManager()->hasListeners(Events::preFlush)) {
            $this->em->getEventManager()->dispatchEvent(Events::preFlush, new PreFlushEventArgs($this->em));
        }

        $this->dispatchOnFlushEvent();
        $commitOrder = $this->getEntitiesCommitOrder();

        try {
            if (!empty($this->entityInsertions)) {
                foreach ($commitOrder as $className) {
                    $this->executeInserts($className);
                }
            }

            if (!empty($this->entityUpdates)) {
                foreach ($commitOrder as $className) {
                    $this->executeUpdates($className);
                }
            }

            if (!empty($this->entityDeletions)) {
                for ($count = count($commitOrder), $i = $count - 1; $i >= 0 && $this->entityDeletions; --$i) {
                    $this->executeDeletions($commitOrder[$i]);
                }
            }

        } catch (\Exception $e) {
            $this->afterTransactionRolledBack();
            throw $e;
        }

        $this->afterTransactionComplete();
        $this->dispatchPostFlushEvent();
        $this->clear($entity);
    }

    public function executeInserts($className) {
        $persister = $this->getEntityPersister($className);

        foreach ($this->entityInsertions as $oid => $entity) {
            if (get_class($entity) !== $className) {
                continue;
            }

            $persister->addInsert($entity);
            unset($this->entityInsertions[$oid]);
        }

        $persister->executeInserts();
    }

    public function executeUpdates($className) {
        $persister = $this->getEntityPersister($className);

        foreach ($this->entityUpdates as $oid => $entity) {
            if (get_class($entity) !== $className) {
                continue;
            }

            $persister->update($entity);
            unset($this->entityUpdates[$oid]);
        }
    }

    public function executeDeletions($className) {
        $persister = $this->getEntityPersister($className);

        foreach ($this->entityDeletions as $oid => $entity) {
            if (get_class($entity) !== $className) {
                continue;
            }

            $persister->delete($entity);
            unset($this->entityDeletions[$oid]);
        }
    }

    protected function dispatchOnFlushEvent() {

    }

    protected function dispatchPostFlushEvent() {

    }

    public function clear($entity = null) {
        if ($entity === null) {
            $this->entityInsertions =
            $this->entityUpdates =
            $this->entityDeletions =
            $this->entitiesCommitOrder = [];
        } else {
            $this->clearEntityInsertions($entity);
            $this->clearEntityUpdate($entity);
            $this->clearEntityDeletions($entity);
        }

        if ($this->em->getEventManager()->hasListeners(Events::onClear)) {
            $this->em->getEventManager()->dispatchEvent(
                Events::onClear, new OnClearEventArgs($this->em, get_class($entity))
            );
        }
    }

    private function clearEntityInsertions($entity = null) {
        if ($entity === null) {
            $this->entityInsertions = [];
        } else {
            $oid = spl_object_hash($entity);
            if (isset($this->entityInsertions[$oid])) {
                unset($this->entityInsertions[$oid]);
            }
        }

    }

    private function clearEntityUpdate($entity = null) {
        if ($entity === null) {
            $this->entityUpdates = [];
        } else {
            $oid = spl_object_hash($entity);
            if (isset($this->entityUpdates[$oid])) {
                unset($this->entityUpdates[$oid]);
            }
        }

    }

    public function delete($entity) {
        if(!is_object($entity)){
            throw new InvalidArgumentException('Trying to schedule a non object to delete');
        }

        $this->scheduleForDelete($entity);
        $this->entitiesCommitOrder[] = get_class($entity);
    }

    private function clearEntityDeletions($entity = null) {
        if ($entity === null) {
            $this->entityDeletions = [];
        } else {
            $oid = spl_object_hash($entity);
            if (isset($this->entityDeletions[$oid])) {
                unset($this->entityDeletions[$oid]);
            }
        }

    }

    /**
     * @return string[]
     */
    public function getEntitiesCommitOrder() {
        return $this->entitiesCommitOrder;
    }

    protected function afterTransactionRolledBack() {

    }

    protected function afterTransactionComplete() {

    }

    public function createEntity() {

    }
}