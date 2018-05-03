<?php

namespace TYPO3\Surf\Task;

/*
 * This file is part of TYPO3 Surf.
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

use TYPO3\Surf\Domain\Clock\ClockInterface;
use TYPO3\Surf\Domain\Clock\SystemClock;
use TYPO3\Surf\Domain\Model\Application;
use TYPO3\Surf\Domain\Model\Deployment;
use TYPO3\Surf\Domain\Model\Node;
use TYPO3\Surf\Domain\Model\Task;
use TYPO3\Surf\Domain\Service\ShellCommandServiceAwareInterface;
use TYPO3\Surf\Domain\Service\ShellCommandServiceAwareTrait;

/**
 * A cleanup task to delete old (unused) releases.
 *
 * Cleanup old releases by listing all releases and keeping a configurable
 * number of old releases (application option "keepReleases"). The current
 * and previous release (if one exists) are protected from removal.
 *
 * Note: There is no rollback for this cleanup, so we have to be sure not to delete any
 *       live or referenced releases.
 *
 * It takes the following options:
 *
 * * keepReleases - The number of releases to keep.
 * * onlyRemoveReleasesOlderThanXSeconds - Remove only those releases older than the defined seconds
 *
 * Example configuration:
 *     $application->setOption('keepReleases', 2);
 *     $application->setOption('onlyRemoveReleasesOlderThan', '121 seconds ago')
 * Note: There is no rollback for this cleanup, so we have to be sure not to delete any
 *       live or referenced releases.
 */
class CleanupReleasesTask extends Task implements ShellCommandServiceAwareInterface
{
    use ShellCommandServiceAwareTrait;

    /**
     * @var ClockInterface|SystemClock|null
     */
    private $clock;

    /**
     * CleanupReleasesTask constructor.
     *
     * @param ClockInterface|null $clock
     */
    public function __construct(ClockInterface $clock = null)
    {
        if (null === $clock) {
            $clock = new SystemClock();
        }

        $this->clock = $clock;
    }

    /**
     * Execute this task
     *
     * @param \TYPO3\Surf\Domain\Model\Node $node
     * @param \TYPO3\Surf\Domain\Model\Application $application
     * @param \TYPO3\Surf\Domain\Model\Deployment $deployment
     * @param array $options
     *
     * @return void|null
     */
    public function execute(Node $node, Application $application, Deployment $deployment, array $options = [])
    {
        if (! isset($options['keepReleases']) && ! isset($options['onlyRemoveReleasesOlderThan'])) {
            $deployment->getLogger()->debug(($deployment->isDryRun() ? 'Would keep' : 'Keeping') . ' all releases for "' . $application->getName() . '"');
            return;
        }

        $releasesPath = $application->getReleasesPath();
        $currentReleaseIdentifier = $deployment->getReleaseIdentifier();
        $previousReleasePath = $application->getReleasesPath() . '/previous';
        $previousReleaseIdentifier = trim($this->shell->execute("if [ -h $previousReleasePath ]; then basename `readlink $previousReleasePath` ; fi", $node, $deployment));

        $allReleasesList = $this->shell->execute("if [ -d $releasesPath/. ]; then find $releasesPath/. -maxdepth 1 -type d -exec basename {} \; ; fi", $node, $deployment);
        $allReleases = preg_split('/\s+/', $allReleasesList, -1, PREG_SPLIT_NO_EMPTY);
        $removableReleases = array_map('trim', array_filter($allReleases, function ($release) use ($currentReleaseIdentifier, $previousReleaseIdentifier) {
            return $release !== '.' && $release !== $currentReleaseIdentifier && $release !== $previousReleaseIdentifier && $release !== 'current' && $release !== 'previous';
        }));

        if (isset($options['onlyRemoveReleasesOlderThan'])) {
            $removeReleases = $this->removeReleasesByAge($options, $removableReleases);
        } else {
            $removeReleases = $this->removeReleasesByNumber($options, $removableReleases);
        }

        $removeCommand = array_reduce($removeReleases, function ($command, $removeRelease) use ($releasesPath) {
            return $command . "rm -rf {$releasesPath}/{$removeRelease};rm -f {$releasesPath}/{$removeRelease}REVISION;";
        }, '');

        if (count($removeReleases) > 0) {
            $deployment->getLogger()->info(($deployment->isDryRun() ? 'Would remove' : 'Removing') . ' releases ' . implode(', ', $removeReleases));
            $this->shell->executeOrSimulate($removeCommand, $node, $deployment);
        } else {
            $deployment->getLogger()->info('No releases to remove');
        }
    }

    /**
     * Simulate this task
     *
     * @param Node $node
     * @param Application $application
     * @param Deployment $deployment
     * @param array $options
     */
    public function simulate(Node $node, Application $application, Deployment $deployment, array $options = [])
    {
        $this->execute($node, $application, $deployment, $options);
    }

    /**
     * @param array $options
     * @param array $removableReleases
     *
     * @return array
     */
    private function removeReleasesByAge(array $options, array $removableReleases)
    {
        $onlyRemoveReleasesOlderThan = $this->clock->stringToTime($options['onlyRemoveReleasesOlderThan']);
        $currentTime = $this->clock->currentTime();
        $removeReleases = array_filter($removableReleases, function ($removeRelease) use ($onlyRemoveReleasesOlderThan, $currentTime) {
            return ($currentTime - $this->clock->createTimestampFromFormat('YmdHis', $removeRelease)) > ($currentTime - $onlyRemoveReleasesOlderThan);
        });

        return $removeReleases;
    }

    /**
     * @param array $options
     * @param array $removableReleases
     *
     * @return array
     */
    private function removeReleasesByNumber(array $options, array $removableReleases)
    {
        sort($removableReleases);
        $keepReleases = $options['keepReleases'];
        $removeReleases = array_slice($removableReleases, 0, count($removableReleases) - $keepReleases);

        return $removeReleases;
    }
}
