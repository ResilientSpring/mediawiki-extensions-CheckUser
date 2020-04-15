<?php

use MediaWiki\CheckUser\TokenManager;
use MediaWiki\CheckUser\TokenQueryManager;

/**
 * Test class for TokenQueryManager class
 *
 * @group CheckUser
 *
 * @covers \MediaWiki\CheckUser\TokenQueryManager
 */
class TokenQueryManagerTest extends MediaWikiTestCase {

	/**
	 * @return MockObject|TokenManager
	 */
	private function getMockTokenManager() {
		return $this->getMockBuilder( TokenManager::class )
			->disableOriginalConstructor()->getMock();
	}

	public function testUpdateToken() {
		$tokenManager = $this->getMockTokenManager();
		$tokenQueryManager = $this->getMockBuilder( TokenQueryManager::class )
			->setConstructorArgs( [ $tokenManager ] )
			->setMethods( [ 'getDataFromRequest' ] )
			->getMock();

		$tokenData = [ 'foo' => true, 'bar' => false,  'baz' => 'test' ];

		$tokenQueryManager->method( 'getDataFromRequest' )->willReturn( $tokenData );
		$tokenManager->expects( $this->once() )
			->method( 'encode' )
			->with( $this->anything(), [ 'bar' => true, 'baz' => 'test' ] );

		$tokenQueryManager->updateToken( new FauxRequest(), [ 'foo' => null, 'bar' => true ] );
	}

	public function testGetDataFromRequest() {
		$request = new FauxRequest( [ 'token' => 'token' ] );

		$tokenManager = $this->getMockTokenManager();
		$tokenManager->expects( $this->once() )->method( 'decode' )->with( $this->anything(), 'token' );

		$tokenQueryManager = new TokenQueryManager( $tokenManager );
		$tokenQueryManager->getDataFromRequest( $request );
	}

	public function testGetDataFromRequestWithNoToken() {
		$request = new FauxRequest();

		$tokenManager = $this->getMockTokenManager();
		$tokenQueryManager = new TokenQueryManager( $tokenManager );
		$data = $tokenQueryManager->getDataFromRequest( $request );

		$this->assertSame( [], $data );
	}

	public function testGetDataFromRequestHandlesDecodeException() {
		$tokenManager = $this->getMockTokenManager();
		$tokenManager->method( 'decode' )->willThrowException( new \Exception() );

		$tokenQueryManager = new TokenQueryManager( $tokenManager );
		$request = new FauxRequest( [ 'token' => 'token' ] );
		$data = $tokenQueryManager->getDataFromRequest( $request );

		$this->assertSame( [], $data );
	}
}