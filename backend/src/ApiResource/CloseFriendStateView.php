<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\State\Processor\CloseFriendProcessor;
use App\State\Provider\CloseFriendListProvider;

/** `CloseFriendState` schema and the caller's own close-friends list (FB-18, ADR 012, DATA_MODEL §8). */
#[ApiResource(
    shortName: 'CloseFriendState',
    operations: [
        new Get(uriTemplate: '/me/close-friends', name: 'listMyCloseFriends', output: CloseFriendPage::class, provider: CloseFriendListProvider::class),
        new Put(uriTemplate: '/me/close-friends/{username}', uriVariables: ['username'], name: 'addCloseFriend', read: false, deserialize: false, processor: CloseFriendProcessor::class),
        new Delete(uriTemplate: '/me/close-friends/{username}', uriVariables: ['username'], name: 'removeCloseFriend', status: 200, read: false, deserialize: false, processor: CloseFriendProcessor::class),
    ],
)]
final readonly class CloseFriendStateView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public bool $isCloseFriend,
    ) {
    }
}
