<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Model\ActivityStatistic;
use App\Repository\Loader\ActivityLoader;
use App\Repository\Paginator\LoaderPaginator;
use App\Repository\Paginator\PaginatorInterface;
use App\Repository\Query\ActivityFormTypeQuery;
use App\Repository\Query\ActivityQuery;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Pagerfanta;

/**
 * @extends \Doctrine\ORM\EntityRepository<Activity>
 */
class ActivityRepository extends EntityRepository
{
    /**
     * @param mixed $id
     * @param null $lockMode
     * @param null $lockVersion
     * @return Activity|null
     */
    public function find($id, $lockMode = null, $lockVersion = null)
    {
        /** @var Activity|null $activity */
        $activity = parent::find($id, $lockMode, $lockVersion);
        if (null === $activity) {
            return null;
        }

        $loader = new ActivityLoader($this->getEntityManager(), true);
        $loader->loadResults([$activity]);

        return $activity;
    }

    /**
     * @param Project $project
     * @return Activity[]
     */
    public function findByProject(Project $project)
    {
        return $this->findBy(['project' => $project]);
    }

    /**
     * @param int[] $activityIds
     * @return Activity[]
     */
    public function findByIds(array $activityIds)
    {
        $qb = $this->createQueryBuilder('a');
        $qb
            ->where($qb->expr()->in('a.id', ':id'))
            ->setParameter('id', $activityIds)
        ;

        $activities = $qb->getQuery()->getResult();

        $loader = new ActivityLoader($qb->getEntityManager(), true);
        $loader->loadResults($activities);

        return $activities;
    }

    /**
     * @param Activity $activity
     * @throws ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function saveActivity(Activity $activity)
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($activity);
        $entityManager->flush();
    }

    /**
     * @param null|bool $visible
     * @return int
     */
    public function countActivity($visible = null)
    {
        if (null !== $visible) {
            return $this->count(['visible' => (bool) $visible]);
        }

        return $this->count([]);
    }

    /**
     * @deprecated since 1.15 use ActivityStatisticService::getActivityStatistics() instead - will be removed with 2.0
     * @codeCoverageIgnore
     *
     * @param Activity $activity
     * @return ActivityStatistic
     */
    public function getActivityStatistics(Activity $activity): ActivityStatistic
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb
            ->from(Timesheet::class, 't')
            ->addSelect('COUNT(t.id) as amount')
            ->addSelect('COALESCE(SUM(t.duration), 0) as duration')
            ->addSelect('COALESCE(SUM(t.rate), 0) as rate')
            ->addSelect('COALESCE(SUM(t.internalRate), 0) as internal_rate')
            ->where('t.activity = :activity')
            ->setParameter('activity', $activity)
        ;

        $timesheetResult = $qb->getQuery()->getOneOrNullResult();

        $stats = new ActivityStatistic();

        if (null !== $timesheetResult) {
            $stats->setCounter($timesheetResult['amount']);
            $stats->setRecordDuration($timesheetResult['duration']);
            $stats->setRecordRate($timesheetResult['rate']);
            $stats->setRecordInternalRate($timesheetResult['internal_rate']);
        }

        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb
            ->from(Timesheet::class, 't')
            ->addSelect('COUNT(t.id) as amount')
            ->addSelect('COALESCE(SUM(t.duration), 0) as duration')
            ->addSelect('COALESCE(SUM(t.rate), 0) as rate')
            ->where('t.activity = :activity')
            ->andWhere('t.billable = :billable')
            ->setParameter('activity', $activity)
            ->setParameter('billable', true, Types::BOOLEAN)
        ;

        $timesheetResult = $qb->getQuery()->getOneOrNullResult();

        if (null !== $timesheetResult) {
            $stats->setDurationBillable($timesheetResult['duration']);
            $stats->setRateBillable($timesheetResult['rate']);
            $stats->setRecordAmountBillable($timesheetResult['amount']);
        }

        return $stats;
    }

    private function addPermissionCriteria(QueryBuilder $qb, ?User $user = null, array $teams = [], bool $globalsOnly = false): void
    {
        $permissions = $this->getPermissionCriteria($qb, $user, $teams, $globalsOnly);
        if ($permissions->count() > 0) {
            $qb->andWhere($permissions);
        }
    }

    private function getPermissionCriteria(QueryBuilder $qb, ?User $user = null, array $teams = [], bool $globalsOnly = false): Andx
    {
        $andX = $qb->expr()->andX();

        // make sure that all queries without a user see all projects
        if (null === $user && empty($teams)) {
            return $andX;
        }

        // make sure that admins see all activities
        if (null !== $user && $user->canSeeAllData()) {
            return $andX;
        }

        if (null !== $user) {
            $teams = array_merge($teams, $user->getTeams());
        }

        if (empty($teams)) {
            $andX->add('SIZE(a.teams) = 0');
            if (!$globalsOnly) {
                $andX->add('SIZE(p.teams) = 0');
                $andX->add('SIZE(c.teams) = 0');
            }

            return $andX;
        }

        $orActivity = $qb->expr()->orX(
            'SIZE(a.teams) = 0',
            $qb->expr()->isMemberOf(':teams', 'a.teams')
        );
        $andX->add($orActivity);

        if (!$globalsOnly) {
            $orProject = $qb->expr()->orX(
                'SIZE(p.teams) = 0',
                $qb->expr()->isMemberOf(':teams', 'p.teams')
            );
            $andX->add($orProject);

            $orCustomer = $qb->expr()->orX(
                'SIZE(c.teams) = 0',
                $qb->expr()->isMemberOf(':teams', 'c.teams')
            );
            $andX->add($orCustomer);
        }

        $ids = array_values(array_unique(array_map(function (Team $team) {
            return $team->getId();
        }, $teams)));

        $qb->setParameter('teams', $ids);

        return $andX;
    }

    /**
     * @deprecated since 1.1 - use getQueryBuilderForFormType() instead - will be removed with 2.0
     * @codeCoverageIgnore
     */
    public function builderForEntityType($activity, $project)
    {
        $query = new ActivityFormTypeQuery();
        $query->addActivity($activity);
        $query->addProject($project);

        return $this->getQueryBuilderForFormType($query);
    }

    /**
     * Returns a query builder that is used for ActivityType and your own 'query_builder' option.
     *
     * @param ActivityFormTypeQuery $query
     * @return QueryBuilder
     */
    public function getQueryBuilderForFormType(ActivityFormTypeQuery $query): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb->select('a')
            ->from(Activity::class, 'a')
            ->addOrderBy('a.project', 'DESC')
            ->addOrderBy('a.name', 'ASC')
        ;

        $mainQuery = $qb->expr()->andX();

        $mainQuery->add($qb->expr()->eq('a.visible', ':visible'));
        $qb->setParameter('visible', true, \PDO::PARAM_BOOL);

        if (!$query->isGlobalsOnly()) {
            $qb
                ->addSelect('p')
                ->addSelect('c')
                ->leftJoin('a.project', 'p')
                ->leftJoin('p.customer', 'c');

            $mainQuery->add(
                $qb->expr()->orX(
                    $qb->expr()->isNull('a.project'),
                    $qb->expr()->andX(
                        $qb->expr()->eq('p.visible', ':is_visible'),
                        $qb->expr()->eq('c.visible', ':is_visible')
                    )
                )
            );

            $qb->setParameter('is_visible', true, \PDO::PARAM_BOOL);
        }

        if ($query->isGlobalsOnly()) {
            $mainQuery->add($qb->expr()->isNull('a.project'));
        } elseif ($query->hasProjects()) {
            $mainQuery->add(
                $qb->expr()->orX(
                    $qb->expr()->isNull('a.project'),
                    $qb->expr()->in('a.project', ':project')
                )
            );
            $qb->setParameter('project', $query->getProjects());
        }

        $permissions = $this->getPermissionCriteria($qb, $query->getUser(), $query->getTeams(), $query->isGlobalsOnly());
        if ($permissions->count() > 0) {
            $mainQuery->add($permissions);
        }

        $outerQuery = $qb->expr()->orX();

        if ($query->hasActivities()) {
            $outerQuery->add($qb->expr()->in('a.id', ':activity'));
            $qb->setParameter('activity', $query->getActivities());
        }

        if (null !== $query->getActivityToIgnore()) {
            $mainQuery = $qb->expr()->andX(
                $mainQuery,
                $qb->expr()->neq('a.id', ':ignored')
            );
            $qb->setParameter('ignored', $query->getActivityToIgnore());
        }

        $outerQuery->add($mainQuery);

        $qb->andWhere($outerQuery);

        return $qb;
    }

    private function getQueryBuilderForQuery(ActivityQuery $query): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb
            ->select('a')
            ->from(Activity::class, 'a')
            ->leftJoin('a.project', 'p')
            ->leftJoin('p.customer', 'c')
        ;

        foreach ($query->getOrderGroups() as $orderBy => $order) {
            switch ($orderBy) {
                case 'project':
                    $orderBy = 'p.name';
                    break;
                case 'customer':
                    $orderBy = 'c.name';
                    break;
                default:
                    $orderBy = 'a.' . $orderBy;
                    break;
            }
            $qb->addOrderBy($orderBy, $order);
        }

        $where = $qb->expr()->andX();

        if (!$query->isShowBoth()) {
            $where->add($qb->expr()->eq('a.visible', ':visible'));

            if (!$query->isGlobalsOnly()) {
                $where->add(
                    $qb->expr()->orX(
                        $qb->expr()->isNull('a.project'),
                        $qb->expr()->andX(
                            $qb->expr()->eq('p.visible', ':is_visible'),
                            $qb->expr()->eq('c.visible', ':is_visible')
                        )
                    )
                );
                $qb->setParameter('is_visible', true, \PDO::PARAM_BOOL);
            }

            if ($query->isShowVisible()) {
                $qb->setParameter('visible', true, \PDO::PARAM_BOOL);
            } elseif ($query->isShowHidden()) {
                $qb->setParameter('visible', false, \PDO::PARAM_BOOL);
            }
        }

        if ($query->isGlobalsOnly()) {
            $where->add($qb->expr()->isNull('a.project'));
        } elseif ($query->hasProjects()) {
            $orX = $qb->expr()->orX(
                $qb->expr()->in('a.project', ':project')
            );

            if (!$query->isExcludeGlobals()) {
                $orX->add($qb->expr()->isNull('a.project'));
            }

            $where->add($orX);
            $qb->setParameter('project', $query->getProjects());
        } elseif ($query->hasCustomers()) {
            $where->add($qb->expr()->in('p.customer', ':customer'));
            $qb->setParameter('customer', $query->getCustomers());
        }

        if ($where->count() > 0) {
            $qb->andWhere($where);
        }

        $this->addPermissionCriteria($qb, $query->getCurrentUser(), $query->getTeams(), $query->isGlobalsOnly());

        if ($query->hasSearchTerm()) {
            $searchAnd = $qb->expr()->andX();
            $searchTerm = $query->getSearchTerm();

            foreach ($searchTerm->getSearchFields() as $metaName => $metaValue) {
                $qb->leftJoin('a.meta', 'meta');
                $searchAnd->add(
                    $qb->expr()->andX(
                        $qb->expr()->eq('meta.name', ':metaName'),
                        $qb->expr()->like('meta.value', ':metaValue')
                    )
                );
                $qb->setParameter('metaName', $metaName);
                $qb->setParameter('metaValue', '%' . $metaValue . '%');
            }

            if ($searchTerm->hasSearchTerm()) {
                $searchAnd->add(
                    $qb->expr()->orX(
                        $qb->expr()->like('a.name', ':searchTerm'),
                        $qb->expr()->like('a.comment', ':searchTerm')
                    )
                );
                $qb->setParameter('searchTerm', '%' . $searchTerm->getSearchTerm() . '%');
            }

            if ($searchAnd->count() > 0) {
                $qb->andWhere($searchAnd);
            }
        }

        return $qb;
    }

    public function countActivitiesForQuery(ActivityQuery $query): int
    {
        $qb = $this->getQueryBuilderForQuery($query);
        $qb
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->resetDQLPart('groupBy')
            ->select($qb->expr()->countDistinct('a.id'))
        ;

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getPagerfantaForQuery(ActivityQuery $query): Pagerfanta
    {
        $paginator = new Pagerfanta($this->getPaginatorForQuery($query));
        $paginator->setMaxPerPage($query->getPageSize());
        $paginator->setCurrentPage($query->getPage());

        return $paginator;
    }

    protected function getPaginatorForQuery(ActivityQuery $query): PaginatorInterface
    {
        $counter = $this->countActivitiesForQuery($query);
        $qb = $this->getQueryBuilderForQuery($query);

        return new LoaderPaginator(new ActivityLoader($qb->getEntityManager()), $qb, $counter);
    }

    /**
     * @param ActivityQuery $query
     * @return Activity[]
     */
    public function getActivitiesForQuery(ActivityQuery $query): iterable
    {
        // this is using the paginator internally, as it will load all joined entities into the working unit
        // do not "optimize" to use the query directly, as it would results in hundreds of additional lazy queries
        $paginator = $this->getPaginatorForQuery($query);

        return $paginator->getAll();
    }

    /**
     * @param Activity $delete
     * @param Activity|null $replace
     * @throws \Doctrine\ORM\ORMException
     */
    public function deleteActivity(Activity $delete, ?Activity $replace = null)
    {
        $em = $this->getEntityManager();
        $em->beginTransaction();

        try {
            if (null !== $replace) {
                $qb = $em->createQueryBuilder();
                $qb->update(Timesheet::class, 't')
                    ->set('t.activity', ':replace')
                    ->where('t.activity = :delete')
                    ->setParameter('delete', $delete)
                    ->setParameter('replace', $replace);

                $qb->getQuery()->execute();
            }

            $em->remove($delete);
            $em->flush();
            $em->commit();
        } catch (ORMException $ex) {
            $em->rollback();
            throw $ex;
        }
    }
}
