<?php

/*
 * This file is apart of the DiscordPHP project.
 *
 * Copyright (c) 2016-2020 David Cole <david.cole1340@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Parts\Channel;

use Carbon\Carbon;
use Discord\Exceptions\FileNotFoundException;
use Discord\Exceptions\InvalidOverwriteException;
use Discord\Helpers\Collection;
use Discord\Parts\Embed\Embed;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Guild\Invite;
use Discord\Parts\Guild\Role;
use Discord\Parts\Part;
use Discord\Parts\Permissions\ChannelPermission;
use Discord\Parts\Permissions\Permission;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use Discord\Repository\Channel\MessageRepository;
use Discord\Repository\Channel\OverwriteRepository;
use Discord\Repository\Channel\VoiceMemberRepository as MemberRepository;
use Discord\Repository\Channel\WebhookRepository;
use Discord\WebSockets\Event;
use React\Promise\Deferred;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Traversable;

/**
 * A Channel can be either a text or voice channel on a Discord guild.
 *
 * @property string                     $id              The unique identifier of the Channel.
 * @property string                     $name            The name of the channel.
 * @property int                        $type            The type of the channel.
 * @property string                     $topic           The topic of the channel.
 * @property Guild                      $guild           The guild that the channel belongs to. Only for text or voice channels.
 * @property string|null                $guild_id        The unique identifier of the guild that the channel belongs to. Only for text or voice channels.
 * @property int                        $position        The position of the channel on the sidebar.
 * @property bool                       $is_private      Whether the channel is a private channel.
 * @property string                     $last_message_id The unique identifier of the last message sent in the channel.
 * @property int                        $bitrate         The bitrate of the channel. Only for voice channels.
 * @property User                       $recipient       The first recipient of the channel. Only for DM or group channels.
 * @property Collection|User[]          $recipients      A collection of all the recipients in the channel. Only for DM or group channels.
 * @property bool                       $nsfw            Whether the channel is NSFW.
 * @property int                        $user_limit      The user limit of the channel.
 * @property int                        $rate_limit_per_user Amount of seconds a user has to wait before sending a new message.
 * @property string                     $icon            Icon hash.
 * @property string                     $owner_id        The ID of the DM creator. Only for DM or group channels.
 * @property string                     $application_id  ID of the group DM creator if it is a bot.
 * @property string                     $parent_id       ID of the parent channel.
 * @property Carbon                     $last_pin_timestamp When the last message was pinned.
 * @property MemberRepository           $members
 * @property MessageRepository          $messages
 * @property OverwriteRepository        $overwrites
 * @property WebhookRepository          $webhooks
 */
class Channel extends Part
{
    const TYPE_TEXT = 0;
    const TYPE_DM = 1;
    const TYPE_VOICE = 2;
    const TYPE_GROUP = 3;
    const TYPE_CATEGORY = 4;
    const TYPE_NEWS = 5;
    const TYPE_GAME_STORE = 6;

    /**
     * {@inheritdoc}
     */
    protected $fillable = [
        'id',
        'name',
        'type',
        'topic',
        'guild_id',
        'position',
        'is_private',
        'last_message_id',
        'permission_overwrites',
        'bitrate',
        'recipients',
        'nsfw',
        'user_limit',
        'rate_limit_per_user',
        'icon',
        'owner_id',
        'application_id',
        'parent_id',
        'last_pin_timestamp',
    ];

    /**
     * {@inheritdoc}
     */
    protected $repositories = [
        'members' => MemberRepository::class,
        'messages' => MessageRepository::class,
        'overwrites' => OverwriteRepository::class,
        'webhooks' => WebhookRepository::class,
    ];

    /**
     * {@inheritdoc}
     */
    protected function afterConstruct()
    {
        if (! array_key_exists('bitrate', $this->attributes) && $this->type != self::TYPE_TEXT) {
            $this->bitrate = 64000;
        }
    }

    /**
     * Gets the is_private attribute.
     *
     * @return bool Whether the channel is private.
     */
    protected function getIsPrivateAttribute()
    {
        return array_search($this->type, [self::TYPE_DM, self::TYPE_GROUP]) !== false;
    }

    /**
     * Gets the recipient attribute.
     *
     * @return User The recipient.
     */
    protected function getRecipientAttribute()
    {
        return $this->recipients->first();
    }

    /**
     * Gets the recipients attribute.
     *
     * @return Collection A collection of recepients.
     */
    protected function getRecipientsAttribute()
    {
        $recipients = new Collection();

        if (array_key_exists('recipients', $this->attributes)) {
            foreach ((array) $this->attributes['recipients'] as $recipient) {
                $recipients->push($this->factory->create(User::class, $recipient, true));
            }
        }

        return $recipients;
    }
    
    /**
     * Returns the guild attribute.
     *
     * @return Guild The guild attribute.
     */
    protected function getGuildAttribute()
    {
        return $this->discord->guilds->get('id', $this->guild_id);
    }

    /**
     * Gets the last pinned message timestamp.
     *
     * @return Carbon
     */
    protected function getLastPinTimestampAttribute()
    {
        if (isset($this->attributes['last_pin_timestamp'])) {
            return Carbon::parse($this->attributes['last_pin_timestamp']);
        }
    }

    /**
     * Returns the channels pinned messages.
     *
     * @return \React\Promise\Promise
     */
    protected function getPinnedMessages()
    {
        $deferred = new Deferred();

        $this->http->get($this->replaceWithVariables('channels/:id/pins'))->then(
            function ($response) use ($deferred) {
                $messages = new Collection();

                foreach ($response as $message) {
                    $message = $this->factory->create(Message::class, $message, true);
                    $messages->push($message);
                }

                $deferred->resolve($messages);
            },
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Sets permissions in a channel.
     *
     * @param Part  $part  A role or member.
     * @param array $allow An array of permissions to allow.
     * @param array $deny  An array of permissions to deny.
     *
     * @return \React\Promise\Promise
     */
    public function setPermissions(Part $part, array $allow = [], array $deny = [])
    {
        $deferred = new Deferred();

        if ($part instanceof Member) {
            $type = 'member';
        } elseif ($part instanceof Role) {
            $type = 'role';
        } else {
            return \React\Promise\reject(new InvalidOverwriteException('Given part was not one of member or role.'));
        }

        $allow = array_fill_keys($allow, true);
        $deny = array_fill_keys($deny, true);

        $allowPart = $this->factory->create(ChannelPermission::class, $allow);
        $denyPart = $this->factory->create(ChannelPermission::class, $deny);

        $overwrite = $this->factory->create(Overwrite::class, [
            'id' => $part->id,
            'channel_id' => $this->id,
            'type' => $type,
            'allow' => $allowPart->bitwise,
            'deny' => $denyPart->bitwise,
        ]);

        $this->setOverwrite($part, $overwrite)->then(
            \React\Partial\bind([$deferred, 'resolve']),
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Sets an overwrite to the channel.
     *
     * @param Part      $part      A role or member.
     * @param Overwrite $overwrite An overwrite object.
     *
     * @return \React\Promise\Promise
     */
    public function setOverwrite(Part $part, Overwrite $overwrite)
    {
        $deferred = new Deferred();

        if ($part instanceof Member) {
            $type = 'member';
        } elseif ($part instanceof Role) {
            $type = 'role';
        } else {
            return \React\Promise\reject(new InvalidOverwriteException('Given part was not one of member or role.'));
        }

        $payload = [
            'id' => $part->id,
            'type' => $type,
            'allow' => (string) $overwrite->allow->bitwise,
            'deny' => (string) $overwrite->deny->bitwise,
        ];

        if (! $this->created) {
            $this->attributes['permission_overwrites'][] = $payload;
            $deferred->resolve();
        } else {
            $this->http->put("channels/{$this->id}/permissions/{$part->id}", $payload)->then(
                \React\Partial\bind([$deferred, 'resolve']),
                \React\Partial\bind([$deferred, 'reject'])
            );
        }

        return $deferred->promise();
    }

    /**
     * Fetches a message object from the Discord servers.
     *
     * @param string $id The message snowflake.
     *
     * @return \React\Promise\Promise
     */
    public function getMessage($id)
    {
        return $this->messages->fetch($id);
    }

    /**
     * Moves a member to another voice channel.
     *
     * @param Member|int The member to move. (either a Member part or the member ID)
     *
     * @return \React\Promise\Promise
     */
    public function moveMember($member)
    {
        $deferred = new Deferred();

        if ($this->getChannelType() != self::TYPE_VOICE) {
            $deferred->reject(new \Exception('You cannot move a member in a text channel.'));

            return $deferred->promise();
        }

        if ($member instanceof Member) {
            $member = $member->id;
        }

        $this->http->patch("guilds/{$this->guild_id}/members/{$member}", ['channel_id' => $this->id])->then(
            \React\Partial\bind([$deferred, 'resolve']),
            \React\Partial\bind([$deferred, 'reject'])
        );

        // At the moment we are unable to check if the member
        // was moved successfully.

        return $deferred->promise();
    }

    /**
     * Creates an invite for the channel.
     *
     * @param array $options              An array of options. All fields are optional.
     * @param int   $options['max_age']   The time that the invite will be valid in seconds.
     * @param int   $options['max_uses']  The amount of times the invite can be used.
     * @param bool  $options['temporary'] Whether the invite is for temporary membership.
     * @param bool  $options['unique']    Whether the invite code should be unique (useful for creating many unique one time use invites).
     *
     * @return \React\Promise\Promise
     */
    public function createInvite($options = [])
    {
        $deferred = new Deferred();

        $this->http->post($this->replaceWithVariables('channels/:id/invites'), $options)->then(
            function ($response) use ($deferred) {
                $invite = $this->factory->create(Invite::class, $response, true);

                $deferred->resolve($invite);
            },
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Bulk deletes an array of messages.
     *
     * @param array|Traversable $messages An array of messages to delete.
     *
     * @return \React\Promise\Promise
     */
    public function deleteMessages($messages)
    {
        $deferred = new Deferred();

        if (! is_array($messages) &&
            ! ($messages instanceof Traversable)
        ) {
            $deferred->reject(new \Exception('$messages must be an array or implement Traversable.'));

            return $deferred->promise();
        }

        $count = count($messages);

        if ($count == 0) {
            $deferred->reject(new \Exception('You cannot delete 0 messages.'));

            return $deferred->promise();
        } elseif ($count == 1) {
            $deferred->reject(new \Exception('You cannot delete 1 message.'));

            return $deferred->promise();
        }

        $messageID = [];

        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $messageID[] = $message->id;
            } else {
                $messageID[] = $message;
            }
        }

        $this->http->post(
            "channels/{$this->id}/messages/bulk_delete",
            [
                'messages' => $messageID,
            ]
        )->then(
            \React\Partial\bind([$deferred, 'resolve']),
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Fetches message history.
     *
     * @param array $options
     *
     * @return \React\Promise\Promise
     */
    public function getMessageHistory(array $options)
    {
        $deferred = new Deferred();

        $resolver = new OptionsResolver();
        $resolver->setDefaults(['limit' => 100, 'cache' => true]);
        $resolver->setDefined(['before', 'after', 'around']);
        $resolver->setAllowedTypes('before', [Message::class, 'string']);
        $resolver->setAllowedTypes('after', [Message::class, 'string']);
        $resolver->setAllowedTypes('around', [Message::class, 'string']);
        $resolver->setAllowedValues('limit', range(1, 100));

        $options = $resolver->resolve($options);
        if (isset($options['before'], $options['after']) ||
            isset($options['before'], $options['around']) ||
            isset($options['around'], $options['after'])) {
            $deferred->reject(new \Exception('Can only specify one of before, after and around.'));

            return $deferred->promise();
        }

        $url = "channels/{$this->id}/messages?limit={$options['limit']}";
        if (isset($options['before'])) {
            $url .= '&before='.($options['before'] instanceof Message ? $options['before']->id : $options['before']);
        }
        if (isset($options['after'])) {
            $url .= '&after='.($options['after'] instanceof Message ? $options['after']->id : $options['after']);
        }
        if (isset($options['around'])) {
            $url .= '&around='.($options['around'] instanceof Message ? $options['around']->id : $options['around']);
        }

        $this->http->get($url, null, [], $options['cache'] ? null : 0)->then(
            function ($response) use ($deferred) {
                $messages = new Collection();

                foreach ($response as $message) {
                    $message = $this->factory->create(Message::class, $message, true);
                    $messages->push($message);
                }

                $deferred->resolve($messages);
            },
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Adds a message to the channels pinboard.
     *
     * @param Message $message The message to pin.
     *
     * @return \React\Promise\Promise
     */
    public function pinMessage(Message $message)
    {
        $deferred = new Deferred();

        if ($message->pinned) {
            return \React\Promise\reject(new \Exception('This message is already pinned.'));
        }

        if ($message->channel_id != $this->id) {
            return \React\Promise\reject(new \Exception('You cannot pin a message to a different channel.'));
        }

        $this->http->put("channels/{$this->id}/pins/{$message->id}")->then(
            function () use (&$message, $deferred) {
                $message->pinned = true;
                $deferred->resolve($message);
            },
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Removes a message from the channels pinboard.
     *
     * @param Message $message The message to un-pin.
     *
     * @return \React\Promise\Promise
     */
    public function unpinMessage(Message $message)
    {
        $deferred = new Deferred();

        if (! $message->pinned) {
            return \React\Promise\reject(new \Exception('This message is not pinned.'));
        }

        if ($message->channel_id != $this->id) {
            return \React\Promise\reject(new \Exception('You cannot un-pin a message from a different channel.'));
        }

        $this->http->delete("channels/{$this->id}/pins/{$message->id}")->then(
            function () use (&$message, $deferred) {
                $message->pinned = false;
                $deferred->resolve($message);
            },
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Returns the channels invites.
     *
     * @return \React\Promise\Promise
     */
    public function getInvites()
    {
        $deferred = new Deferred();

        $this->http->get($this->replaceWithVariables('channels/:id/invites'))->then(
            function ($response) use ($deferred) {
                $invites = new Collection();

                foreach ($response as $invite) {
                    $invite = $this->factory->create(Invite::class, $invite, true);
                    $invites->push($invite);
                }

                $deferred->resolve($invites);
            },
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Sets the permission overwrites attribute.
     *
     * @param array $overwrites
     */
    protected function setPermissionOverwritesAttribute($overwrites)
    {
        $this->attributes['permission_overwrites'] = $overwrites;

        if (! is_null($overwrites)) {
            foreach ($overwrites as $overwrite) {
                $overwrite = (array) $overwrite;
                $overwrite['channel_id'] = $this->id;

                $this->overwrites->push($overwrite);
            }
        }
    }

    /**
     * Sends a message to the channel if it is a text channel.
     *
     * @param string $text  The text to send in the message.
     * @param bool   $tts   Whether the message should be sent with text to speech enabled.
     * @param Embed  $embed An embed to send.
     *
     * @return \React\Promise\Promise
     */
    public function sendMessage($text, $tts = false, $embed = null)
    {
        $deferred = new Deferred();

        if ($this->getChannelType() != self::TYPE_TEXT) {
            $deferred->reject(new \Exception('You cannot send a message to a voice channel.'));

            return $deferred->promise();
        }

        $this->http->post(
            "channels/{$this->id}/messages",
            [
                'content' => $text,
                'tts' => $tts,
                'embed' => $embed,
            ]
        )->then(
            function ($response) use ($deferred) {
                $message = $this->factory->create(Message::class, $response, true);
                $this->messages->push($message);

                $deferred->resolve($message);
            },
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Sends an embed to the channel if it is a text channel.
     *
     * @param Embed $embed
     *
     * @return \React\Promise\Promise
     */
    public function sendEmbed(Embed $embed)
    {
        $deferred = new Deferred();

        if ($this->getChannelType() != self::TYPE_TEXT) {
            $deferred->reject(new \Exception('You cannot send an embed to a voice channel.'));

            return $deferred->promise();
        }

        $this->http->post("channels/{$this->id}/messages", ['embed' => $embed->getRawAttributes()])->then(function ($response) use ($deferred) {
            $message = $this->factory->create(Message::class, $response, true);
            $this->messages->push($message);

            $deferred->resolve($message);
        }, \React\Partial\bind([$deferred, 'reject']));

        return $deferred->promise();
    }

    /**
     * Sends a file to the channel if it is a text channel.
     *
     * @param string $filepath The path to the file to be sent.
     * @param string $filename The name to send the file as.
     * @param string $content  Message content to send with the file.
     * @param bool   $tts      Whether to send the message with TTS.
     *
     * @return \React\Promise\Promise
     */
    public function sendFile($filepath, $filename = null, $content = null, $tts = false)
    {
        $deferred = new Deferred();

        if ($this->getChannelType() != self::TYPE_TEXT) {
            $deferred->reject(new \Exception('You cannot send a file to a voice channel.'));

            return $deferred->promise();
        }

        if (! file_exists($filepath)) {
            $deferred->reject(new FileNotFoundException("File does not exist at path {$filepath}."));

            return $deferred->promise();
        }

        if (is_null($filename)) {
            $filename = basename($filepath);
        }

        $this->http->sendFile($this, $filepath, $filename, $content, $tts)->then(
            function ($response) use ($deferred) {
                $message = $this->factory->create(Message::class, $response, true);
                $this->messages->push($message);

                $deferred->resolve($message);
            },
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->promise();
    }

    /**
     * Broadcasts that you are typing to the channel. Lasts for 5 seconds.
     *
     * @return bool Whether the request succeeded or failed.
     */
    public function broadcastTyping()
    {
        $deferred = new Deferred();

        if ($this->getChannelType() != self::TYPE_TEXT) {
            $deferred->reject(new \Exception('You cannot broadcast typing to a voice channel.'));

            return $deferred->promise();
        }

        $this->http->post("channels/{$this->id}/typing")->then(
            \React\Partial\bind([$deferred, 'resolve']),
            \React\Partial\bind([$deferred, 'reject'])
        );

        return $deferred->resolve();
    }

    /**
     * Creates a message collector for the channel.
     *
     * @param callable $filter           The filter function. Returns true or false.
     * @param array    $options
     * @param int      $options['time']  Time in milliseconds until the collector finishes or false.
     * @param int      $options['limit'] The amount of messages allowed or false.
     *
     * @return \React\Promise\Promise
     */
    public function createMessageCollector($filter, $options = [])
    {
        $deferred = new Deferred();
        $messages = new Collection();
        $timer = null;

        $options = array_merge([
            'time' => false,
            'limit' => false,
        ], $options);

        $eventHandler = function (Message $message) use (&$eventHandler, $filter, $options, &$messages, &$deferred, &$timer) {
            if ($message->channel_id != $this->id) {
                return;
            }
            // Reject messages not in this channel
            $filterResult = call_user_func_array($filter, [$message]);

            if ($filterResult) {
                $messages->push($message);

                if ($options['limit'] !== false && sizeof($messages) >= $options['limit']) {
                    $this->discord->removeListener(Event::MESSAGE_CREATE, $eventHandler);
                    $deferred->resolve($messages);

                    if (! is_null($timer)) {
                        $this->discord->getLoop()->cancelTimer($timer);
                    }
                }
            }
        };
        $this->discord->on(Event::MESSAGE_CREATE, $eventHandler);

        if ($options['time'] !== false) {
            $timer = $this->discord->getLoop()->addTimer($options['time'] / 1000, function () use (&$eventHandler, &$messages, &$deferred) {
                $this->discord->removeListener(Event::MESSAGE_CREATE, $eventHandler);
                $deferred->resolve($messages);
            });
        }

        return $deferred->promise();
    }

    /**
     * Returns the channel type.
     *
     * @return string Either 'text' or 'voice'.
     */
    public function getChannelType()
    {
        switch ($this->type) {
            case self::TYPE_TEXT:
            case self::TYPE_VOICE:
                return $this->type;
                break;
            default:
                return self::TYPE_TEXT;
                break;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getCreatableAttributes()
    {
        return [
            'name' => $this->name,
            'type' => $this->getChannelType(),
            'bitrate' => $this->bitrate,
            'permission_overwrites' => $this->permission_overwrites,
            'topic' => $this->topic,
            'user_limit' => $this->user_limit,
            'rate_limit_per_user' => $this->rate_limit_per_user,
            'position' => $this->position,
            'parent_id' => $this->parent_id,
            'nsfw' => $this->nsfw,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getUpdatableAttributes()
    {
        return [
            'name' => $this->name,
            'topic' => $this->topic,
            'position' => $this->position,
            'parent_id' => $this->parent_id,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getRepositoryAttributes()
    {
        return [
            'channel_id' => $this->id,
        ];
    }
}
