<?php
/**
 * ResqueScheduler worker to handle scheduling of delayed tasks.
 *
 * @package		ResqueScheduler
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @copyright	(c) 2012 Chris Boulton
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class ResqueScheduler_Worker
{
	/**
	* @var LoggerInterface Logging object that impliments the PSR-3 LoggerInterface
	*/
	public $logger;
	
	/**
	 * @var int Interval to sleep for between checking schedules.
	 */
	protected $interval = null;
	
	/**
	 * @var string The hostname of this worker.
	 */
	private $hostname;

	/**
	 * @var string String identifying this worker.
	 */
	private $id;

	/**
	 * @var boolean True if on the next iteration, the worker should shutdown.
	 */
	private $shutdown = false;

    /**
     * Instantiate a new worker
     */
    public function __construct()
    {
        $this->logger = new Resque_Log();

        if(function_exists('gethostname')) {
            $hostname = gethostname();
        }
        else {
            $hostname = php_uname('n');
        }
        $this->hostname = $hostname;
        
        $this->id = $this->hostname . ':' . getmypid();
    }
        
	/**
	* The primary loop for a worker.
	*
	* Every $interval (seconds), the scheduled queue will be checked for jobs
	* that should be pushed to Resque.
	*
	* @param int $interval How often to check schedules.
	*/
	public function work($interval = ResqueScheduler::DEFAULT_INTERVAL)
	{
        $this->interval = $interval;

		$this->updateProcLine('Starting');
        $this->registerSigHandlers();
		
		while (true) {
			if($this->shutdown) {
				break;
			}
            
			$this->handleDelayedItems();
			$this->sleep();
		}
	}
	
	/**
	 * Handle delayed items for the next scheduled timestamp.
	 *
	 * Searches for any items that are due to be scheduled in Resque
	 * and adds them to the appropriate job queue in Resque.
	 *
	 * @param DateTime|int $timestamp Search for any items up to this timestamp to schedule.
	 */
	public function handleDelayedItems($timestamp = null)
	{
		while (($timestamp = ResqueScheduler::nextDelayedTimestamp($timestamp)) !== false) {
			$this->updateProcLine('Processing Delayed Items');
			$this->enqueueDelayedItemsForTimestamp($timestamp);
		}
	}
	
	/**
	 * Schedule all of the delayed jobs for a given timestamp.
	 *
	 * Searches for all items for a given timestamp, pulls them off the list of
	 * delayed jobs and pushes them across to Resque.
	 *
	 * @param DateTime|int $timestamp Search for any items up to this timestamp to schedule.
	 */
	public function enqueueDelayedItemsForTimestamp($timestamp)
	{
		$item = null;
		while ($item = ResqueScheduler::nextItemForTimestamp($timestamp)) {

            if ($timestamp instanceof DateTime) {
                $timestamp = $timestamp->getTimestamp();
            }
            
			$this->logger->log(
                    Psr\Log\LogLevel::NOTICE, 
                    'Queueing {class} scheduled to {datetime} in {queue} queue with args {args}', 
                    array('class' => $item['class'], 'queue' => $item['queue'], 
                        'args' => json_encode($item['args']), 'datetime' => date('Y-m-d H:i:s', $timestamp))
            );
			
			Resque_Event::trigger('beforeDelayedEnqueue', array(
				'queue' => $item['queue'],
				'class' => $item['class'],
				'args'  => $item['args'],
			));

			$payload = array_merge(array($item['queue'], $item['class']), $item['args']);
			call_user_func_array('Resque::enqueue', $payload);
		}
	}
	
	/**
	 * Sleep for the defined interval.
	 */
	protected function sleep()
	{
        usleep($this->interval * 1000000);
	}
	
	/**
	 * Update the status of the current worker process.
	 *
	 * On supported systems (with the PECL proctitle module installed), update
	 * the name of the currently running process to indicate the current state
	 * of a worker.
	 *
	 * @param string $status The updated process title.
	 */
	private function updateProcLine($status)
	{
		if(function_exists('setproctitle')) {
			setproctitle('resque-scheduler-' . ResqueScheduler::VERSION . ': ' . $status);
		}
	}
	
    /**
	 * Inject the logging object into the worker
	 *
	 * @param Psr\Log\LoggerInterface $logger
	 */
	public function setLogger(Psr\Log\LoggerInterface $logger)
	{
		$this->logger = $logger;
	}
    
	/**
	 * Generate a string representation of this worker.
	 *
	 * @return string String identifier for this worker instance.
	 */
	public function __toString()
	{
		return $this->id;
	}

	/**
	 * Register signal handlers that a worker should respond to.
	 *
	 * TERM: Shutdown after the current iteration finishes processing.
	 * INT: Shutdown after the current iteration finishes processing.
	 * QUIT: Shutdown after the current iteration finishes processing.
	 */
	private function registerSigHandlers()
	{
		if(!function_exists('pcntl_signal')) {
			return;
		}

		declare(ticks = 1);
		pcntl_signal(SIGTERM, array($this, 'shutdown'));
		pcntl_signal(SIGINT, array($this, 'shutdown'));
		pcntl_signal(SIGQUIT, array($this, 'shutdown'));
		$this->logger->log(Psr\Log\LogLevel::DEBUG, 'Registered signals');
	}

	/**
	 * Schedule the worker for shutdown. Will finish processing the current 
     * iteration and when the timeout interval is reached, the worker will 
     * shut down.
	 */
	public function shutdown()
	{
		$this->shutdown = true;
		$this->logger->log(Psr\Log\LogLevel::NOTICE, 'Shutting down');
	}

}
