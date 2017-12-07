<?php
/**
 * @file
 * Main Exchange service.
 * Handles cron event, and provides call to get a resource's calendar events.
 */

namespace Itk\ExchangeBundle\Service;

use Doctrine\ORM\EntityManager;
use Os2Display\CoreBundle\Events\CronEvent;
use Doctrine\Common\Cache\CacheProvider;

/**
 * Class ExchangeService
 * @package Itk\ExchangeBundle\Service
 */
class ExchangeService
{
    protected $exchangeWebService;
    protected $serviceEnabled;
    protected $entityManager;
    protected $cache;
    protected $cacheTTL;

    /**
     * ExchangeService constructor.
     *
     * @param CacheProvider $cache
     *   The cache.
     * @param ExchangeWebService $exchangeWebService
     *   The ExchangeWebService service.
     * @param EntityManager $entityManager
     *   The Entity manager.
     * @param $serviceEnabled
     *   Should the service be enabled?
     * @param $cacheTTL
     *   Cache entry time to live.
     */
    public function __construct(
        CacheProvider $cache,
        ExchangeWebService $exchangeWebService,
        EntityManager $entityManager,
        $serviceEnabled,
        $cacheTTL
    ) {
        $this->cache = $cache;
        $this->cache->setNamespace('itk_exchange.cache');

        $this->exchangeWebService = $exchangeWebService;
        $this->serviceEnabled = $serviceEnabled;
        $this->entityManager = $entityManager;
        $this->cacheTTL = $cacheTTL;
    }

    /**
     * ik.onCron event listener.
     *
     * Updates calendar slides.
     *
     * @param CronEvent $event
     *   The cron event.
     */
    public function onCron(CronEvent $event)
    {
        // Only run if enabled.
        if (!$this->serviceEnabled) {
            return;
        }

        $this->updateCalendarSlides();
    }

    /**
     * Get the ExchangeBookings for a resource in an interval.
     *
     * @param $resourceMail
     *   The resource mail.
     * @param $startTime
     *   The start time.
     * @param $endTime
     *   The end time.
     *
     * @return array
     *   Array of ExchangeBookings.
     */
    public function getExchangeBookingsForInterval(
        $resourceMail,
        $startTime,
        $endTime
    ) {
        // Start by getting the bookings from exchange.
        $calendar = $this->exchangeWebService->getResourceBookings(
            $resourceMail,
            $startTime,
            $endTime
        );

        return $calendar->getBookings();
    }

    /**
     * Update the slide.external_data for calendar slides.
     */
    public function updateCalendarSlides()
    {
        // For each calendar slide
        $slides = $this->entityManager
            ->getRepository('Os2DisplayCoreBundle:Slide')
            ->findBySlideType('calendar');

        $start = time();
        // Round down to nearest hour
        $start = $start - ($start % 3600);

        $todayEnd = mktime(23, 59, 59);

        // Get data for interest period
        foreach ($slides as $slide) {
            $bookings = array();

            $options = $slide->getOptions();

            foreach ($options['resources'] as $resource) {
                // Default interval is today + 6 days.
                $interestInterval = 6;

                // Figure out how many days should be added to $todayEnd.
                if (isset($options['interest_interval'])) {
                    if ($options['interest_interval'] <= 1) {
                        $interestInterval = 0;
                    } else {
                        $interestInterval = $options['interest_interval'] - 1;
                    }
                }

                // Move today with number of requested days.
                $end = strtotime('+' . $interestInterval . ' days', $todayEnd);

                $resourceBookings = [];

                $cacheKey = $resource['mail'] . '-' . $start . '-' . $end;

                // Serve cached data if available, else get fresh results from EWS.
                $cachedData = $this->cache->fetch($cacheKey);
                if (false === ($cachedData)) {
                    try {
                        $resourceBookings = $this->getExchangeBookingsForInterval(
                            $resource['mail'],
                            $start,
                            $end
                        );
                    } catch (\Exception $e) {
                        // Ignore exceptions. The show must keep running, even though we have no connection to koba.
                    }

                    $this->cache->save(
                        $cacheKey,
                        $resourceBookings,
                        $this->cacheTTL
                    );
                } else {
                    $resourceBookings = $cachedData;
                }

                // Merge results.
                if (count($resourceBookings) > 0) {
                    $bookings = array_merge($bookings, $resourceBookings);
                }
            }

            // Sort bookings by start time.
            usort($bookings, function ($a, $b) {
                return strcmp($a->getStartTime(), $b->getStartTime());
            });

            // Save to slide.external_data field.
            $slide->setExternalData($bookings);
        }

        // Persist changes.
        $this->entityManager->flush();
    }
}
