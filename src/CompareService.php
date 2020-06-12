<?php

namespace MediaWiki\CheckUser;

use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\Subquery;

class CompareService extends ChangeService {
	/** @var int */
	private $limit;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param UserManager $userManager
	 * @param int $limit Maximum number of rows to access (T245499)
	 */
	public function __construct(
		ILoadBalancer $loadBalancer,
		UserManager $userManager,
		$limit = 100000
	) {
		parent::__construct( $loadBalancer, $userManager );
		$this->limit = $limit;
	}

	/**
	 * Get edits made from an ip
	 *
	 * @param string $ipHex
	 * @param string|null $excludeUser
	 * @return int
	 */
	public function getTotalEditsFromIp(
		string $ipHex,
		string $excludeUser = null
	) : int {
		$db = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		$conds = [
			'cuc_ip_hex' => $ipHex,
			'cuc_type' => [ RC_EDIT, RC_NEW ],
		];

		if ( $excludeUser ) {
			$conds[] = 'cuc_user_text != ' . $db->addQuotes( $excludeUser );
		}

		return $db->selectRowCount( 'cu_changes', '*', $conds, __METHOD__ );
	}

	/**
	 * Get the compare query info
	 *
	 * @param string[] $targets
	 * @param string[] $excludeTargets
	 * @return array
	 */
	public function getQueryInfo( array $targets, array $excludeTargets ): array {
		$db = $this->loadBalancer->getConnectionRef( DB_REPLICA );

		if ( $targets === [] ) {
			throw new \LogicException( 'Cannot get query info when $targets is empty.' );
		}
		$limit = (int)( $this->limit / count( $targets ) );

		$sqlText = [];
		foreach ( $targets as $target ) {
			$info = $this->getQueryInfoForSingleTarget( $target, $excludeTargets, $limit );
			if ( $info !== null ) {
				if ( !$db->unionSupportsOrderAndLimit() ) {
					unset( $info['options']['ORDER BY'], $info['options']['LIMIT'] );
				}
				$sqlText[] = $db->selectSQLText(
					$info['tables'],
					$info['fields'],
					$info['conds'],
					__METHOD__,
					$info['options']
				);
			}
		}

		$derivedTable = $db->unionQueries( $sqlText, $db::UNION_DISTINCT );

		return [
			'tables' => [ 'a' => new Subquery( $derivedTable ) ],
			'fields' => [
				'cuc_user' => 'a.cuc_user',
				'cuc_user_text' => 'a.cuc_user_text',
				'cuc_ip' => 'a.cuc_ip',
				'cuc_ip_hex' => 'a.cuc_ip_hex',
				'cuc_agent' => 'a.cuc_agent',
				'first_edit' => 'MIN(a.cuc_timestamp)',
				'last_edit' => 'MAX(a.cuc_timestamp)',
				'total_edits' => 'count(*)',
			],
			'options' => [
				'GROUP BY' => [
					'cuc_user_text',
					'cuc_ip',
					'cuc_agent',
				],
			],
		];
	}

	/**
	 * Get the query info for a single target.
	 *
	 * For the main investigation, this becomes a subquery that contributes to a derived
	 * table, used by getQueryInfo.
	 *
	 * For a limit check, this query is used to check whether the number of results for
	 * the target exceed the limit-per-target in getQueryInfo.
	 *
	 * @param string $target
	 * @param string[] $excludeTargets
	 * @param int $limitPerTarget
	 * @param bool $limitCheck
	 * @return array|null Return null for invalid target
	 */
	public function getQueryInfoForSingleTarget(
		string $target,
		array $excludeTargets,
		int $limitPerTarget,
		$limitCheck = false
	) : ?array {
		if ( $limitCheck ) {
			$orderBy = null;
			$offset = $limitPerTarget;
			$limit = 1;
		} else {
			$orderBy = 'cuc_timestamp DESC';
			$offset = null;
			$limit = $limitPerTarget;
		}

		$conds = $this->buildTargetConds( $target );
		if ( $conds === [] ) {
			return null;
		}

		$conds = array_merge( $conds, $this->buildExcludeTargetsConds( $excludeTargets ) );

		// TODO: Add timestamp conditions (T246261)
		$conds['cuc_type'] = [ RC_EDIT, RC_NEW ];

		return [
			'tables' => 'cu_changes',
			'fields' => [
				'cuc_id',
				'cuc_user',
				'cuc_user_text',
				'cuc_ip',
				'cuc_ip_hex',
				'cuc_agent',
				'cuc_timestamp',
			],
			'conds' => $conds,
			'options' => [
				'ORDER BY' => $orderBy,
				'LIMIT' => $limit,
				'OFFSET' => $offset,
			],
		];
	}

	/**
	 * Check if we have incomplete data for any of the targets.
	 *
	 * @param string[] $targets
	 * @param string[] $excludeTargets
	 * @return string[]
	 */
	public function getTargetsOverLimit( array $targets, array $excludeTargets ) : array {
		if ( $targets === [] ) {
			return $targets;
		}

		$db = $this->loadBalancer->getConnectionRef( DB_REPLICA );

		// If the database does not support order and limit on a UNION
		// then none of the targets can be over the limit.
		if ( !$db->unionSupportsOrderAndLimit() ) {
			return [];
		}

		$targetsOverLimit = [];
		$offset = (int)( $this->limit / count( $targets ) );

		foreach ( $targets as $target ) {
			$info = $this->getQueryInfoForSingleTarget( $target, $excludeTargets, $offset, true );
			if ( $info !== null ) {
				$limitCheck = $db->select(
					$info['tables'],
					$info['fields'],
					$info['conds'],
					__METHOD__,
					$info['options']
				);
				if ( $limitCheck->numRows() > 0 ) {
					$targetsOverLimit[] = $target;
				}
			}
		}

		return $targetsOverLimit;
	}
}
