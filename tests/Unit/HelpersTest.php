<?php
namespace App\Tests\Unit;

use App\Util\Helpers;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    const V2 = 'https://mon-compte-particulier.enedis.fr/dataconnect/v2/oauth2/authorize';
    const V1 = 'https://mon-compte-particulier.enedis.fr/dataconnect/v1/oauth2/authorize';

    public function testAuthorizationEndpointKeepsV2(): void
    {
        $this->assertSame(['url' => self::V2, 'upgraded_from' => null], Helpers::authorization_endpoint(self::V2));
    }

    public function testAuthorizationEndpointUpgradesV1ToV2(): void
    {
        $this->assertSame(['url' => self::V2, 'upgraded_from' => 'v1'], Helpers::authorization_endpoint(self::V1));
    }

    public function testAuthorizationEndpointUpgradesV1OnAnyHost(): void
    {
        // Le faux serveur Enedis des tests e2e, ou un futur changement de domaine
        $this->assertSame(
            ['url' => 'http://mock:9000/dataconnect/v2/oauth2/authorize', 'upgraded_from' => 'v1'],
            Helpers::authorization_endpoint('http://mock:9000/dataconnect/v1/oauth2/authorize')
        );
    }

    public function testAuthorizationEndpointLeavesCustomUrlsAlone(): void
    {
        $custom = 'https://example.test/oauth2/authorize';
        $this->assertSame(['url' => $custom, 'upgraded_from' => null], Helpers::authorization_endpoint($custom));
    }
}
