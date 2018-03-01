<?php declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Predis\Client as Redis;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Packagist\WebBundle\Entity\Job;
use Seld\Signal\SignalHandler;
use Packagist\WebBundle\Service\LogResetter;

class QueueWorker
{
    private $logResetter;
    private $redis;
    private $logger;
    /** @var RegistryInterface */
    private $doctrine;
    private $jobWorkers;
    private $processedJobs = 0;

    public function __construct(LogResetter $logResetter, Redis $redis, RegistryInterface $doctrine, LoggerInterface $logger, array $jobWorkers)
    {
        $this->logResetter = $logResetter;
        $this->redis = $redis;
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->jobWorkers = $jobWorkers;
    }

    /**
     * @param string|int $minPriority
     */
    public function processMessages(int $count)
    {
        $signal = SignalHandler::create(null, $this->logger);

        $this->logger->info('Waiting for new messages');

        $this->doctrine->getEntityManager()->getRepository(Job::class)->markTimedOutJobs();
        $nextScheduledJobCheck = $this->checkForScheduledJobs($signal);

        while ($this->processedJobs++ < $count) {
            if ($signal->isTriggered()) {
                $this->logger->debug('Signal received, aborting');
                break;
            }

            if ($nextScheduledJobCheck <= time()) {
                $nextScheduledJobCheck = $this->checkForScheduledJobs($signal);
            }

            $result = $this->redis->brpop('jobs', 10);
            if (!$result) {
                $this->logger->debug('No message in queue');
                continue;
            }

            $jobId = $result[1];
            $this->process($jobId, $signal);
        }
    }

    private function checkForScheduledJobs(SignalHandler $signal): int
    {
        $em = $this->doctrine->getEntityManager();
        $repo = $em->getRepository(Job::class);

        foreach ($repo->getScheduledJobIds() as $jobId) {
            if ($this->process($jobId, $signal)) {
                $this->processedJobs++;
            }
        }

        // check for scheduled jobs every 30 sec at least
        return time() + 30;
    }

    /**
     * Calls the configured processor with the job and a callback that must be called to mark the job as processed
     */
    private function process(string $jobId, SignalHandler $signal): bool
    {
        $em = $this->doctrine->getEntityManager();
        $repo = $em->getRepository(Job::class);
        if (!$repo->start($jobId)) {
            // race condition, some other worker caught the job first, aborting
            return false;
        }

        $job = $repo->findOneById($jobId);

        $this->logger->pushProcessor(function ($record) use ($job) {
            $record['extra']['job-id'] = $job->getId();

            return $record;
        });

        $processor = $this->jobWorkers[$job->getType()];

        // clears/resets all fingers-crossed handlers to avoid dumping info messages that happened between two job executions
        $this->logResetter->reset();

        $this->logger->debug('Processing ' . $job->getType() . ' job', ['job' => $job->getPayload()]);

        try {
            $result = $processor->process($job, $signal);
        } catch (\Throwable $e) {
            $result = [
                'status' => Job::STATUS_ERRORED,
                'message' => 'An unexpected failure occurred',
                'exception' => $e,
            ];
        }

        // If an exception is thrown during a transaction the EntityManager is closed
        // and we won't be able to update the job or handle future jobs
        if (!$this->doctrine->getEntityManager()->isOpen()) {
            $this->doctrine->resetManager();
        }

        // refetch objects in case the EM was reset during the job run
        $em = $this->doctrine->getEntityManager();
        $repo = $em->getRepository(Job::class);

        if ($result['status'] === Job::STATUS_RESCHEDULE) {
            $job->reschedule($result['after']);
            $em->flush($job);

            // reset logger
            $this->logResetter->reset();
            $this->logger->popProcessor();

            return true;
        }

        if (!isset($result['message']) || !isset($result['status'])) {
            throw new \LogicException('$result must be an array with at least status and message keys');
        }

        if (!in_array($result['status'], [Job::STATUS_COMPLETED, Job::STATUS_FAILED, Job::STATUS_ERRORED, Job::STATUS_PACKAGE_GONE], true)) {
            throw new \LogicException('$result[\'status\'] must be one of '.Job::STATUS_COMPLETED.' or '.Job::STATUS_FAILED.', '.$result['status'].' given');
        }

        if (isset($result['exception'])) {
            $result['exceptionMsg'] = $result['exception']->getMessage();
            $result['exceptionClass'] = get_class($result['exception']);
        }

        $job = $repo->findOneById($jobId);
        $job->complete($result);

        $this->redis->setex('job-'.$job->getId(), 600, json_encode($result));

        $em->flush($job);
        $em->clear();

        if ($result['status'] === Job::STATUS_FAILED) {
            $this->logger->warning('Job '.$job->getId().' failed', $result);
        } elseif ($result['status'] === Job::STATUS_ERRORED) {
            $this->logger->error('Job '.$job->getId().' errored', $result);
        }

        // clears/resets all fingers-crossed handlers so that if one triggers it doesn't dump the entire debug log for all processed
        $this->logResetter->reset();

        $this->logger->popProcessor();

        return true;
    }
}