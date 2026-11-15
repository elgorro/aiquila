// SPDX-License-Identifier: MIT

/**
 * Sequences built into the server. They need no Nextcloud app: without the
 * AIquila app every user gets all of them, and a step whose app is not
 * installed is skipped. An admin can switch each off for the instance, and a
 * user for themselves, in the app's Sequences settings.
 */

import { SequenceDefinitionSchema, type Sequence } from './definition.js';

const definitions: unknown[] = [
  {
    slug: 'daily_briefing',
    title: 'Daily briefing',
    description:
      "Start the day: the local time, today's events, open tasks, notifications and unread mail.",
    inputs: [
      {
        name: 'calendar',
        type: 'string',
        description: 'Calendar to read events from (its URL name, see list_calendars)',
        default: 'personal',
      },
      {
        name: 'mailAccountId',
        type: 'integer',
        description:
          'Mail account for the unread counts (see list_mail_accounts); skipped if left out',
      },
    ],
    steps: [
      { id: 'time', tool: 'get_local_time', onFailure: 'continue' },
      {
        id: 'events',
        tool: 'list_events',
        arguments: { calendarName: '{{inputs.calendar}}', from: '{{today}}', to: '{{today+1d}}' },
        onFailure: 'continue',
      },
      {
        id: 'tasks',
        tool: 'list_tasks',
        arguments: { status: 'NEEDS-ACTION' },
        onFailure: 'continue',
      },
      { id: 'notifications', tool: 'list_notifications', onFailure: 'continue' },
      {
        id: 'mail',
        tool: 'list_mailboxes',
        arguments: { accountId: '{{inputs.mailAccountId}}' },
        onFailure: 'continue',
      },
    ],
    pinned: true,
  },
  {
    slug: 'inbox_triage',
    title: 'Inbox triage',
    description:
      'Everything waiting for you: unread mail, notifications, shares to accept and Talk conversations.',
    inputs: [
      {
        name: 'mailAccountId',
        type: 'integer',
        description:
          'Mail account for the unread counts (see list_mail_accounts); skipped if left out',
      },
    ],
    steps: [
      {
        id: 'mail',
        tool: 'list_mailboxes',
        arguments: { accountId: '{{inputs.mailAccountId}}' },
        onFailure: 'continue',
      },
      { id: 'notifications', tool: 'list_notifications', onFailure: 'continue' },
      { id: 'shares', tool: 'list_pending_shares', onFailure: 'continue' },
      { id: 'talk', tool: 'talk_list_conversations', onFailure: 'continue' },
    ],
    pinned: true,
  },
  {
    slug: 'weekly_review',
    title: 'Weekly review',
    description:
      "Look back and ahead: recent activity, open tasks and the next seven days' events.",
    inputs: [
      {
        name: 'calendar',
        type: 'string',
        description: 'Calendar to read events from (its URL name, see list_calendars)',
        default: 'personal',
      },
    ],
    steps: [
      {
        id: 'activity',
        tool: 'list_activity',
        arguments: { limit: 100 },
        onFailure: 'continue',
      },
      {
        id: 'tasks',
        tool: 'list_tasks',
        arguments: { status: 'NEEDS-ACTION' },
        onFailure: 'continue',
      },
      {
        id: 'events',
        tool: 'list_events',
        arguments: { calendarName: '{{inputs.calendar}}', from: '{{today}}', to: '{{today+7d}}' },
        onFailure: 'continue',
      },
    ],
    pinned: true,
  },
  {
    slug: 'find_meeting_slot',
    title: 'Find a meeting slot',
    description: 'Free/busy of the attendees and the rooms free in a time range.',
    inputs: [
      { name: 'start', type: 'string', required: true, description: 'Start, ISO 8601 with offset' },
      { name: 'end', type: 'string', required: true, description: 'End, ISO 8601 with offset' },
      {
        name: 'attendees',
        type: 'array',
        required: true,
        description: 'Email addresses of the attendees',
      },
      { name: 'minCapacity', type: 'integer', description: 'People the room must hold' },
    ],
    steps: [
      {
        id: 'freebusy',
        tool: 'get_free_busy',
        arguments: {
          start: '{{inputs.start}}',
          end: '{{inputs.end}}',
          addresses: '{{inputs.attendees}}',
        },
        onFailure: 'continue',
      },
      {
        id: 'rooms',
        tool: 'find_free_rooms',
        arguments: {
          start: '{{inputs.start}}',
          end: '{{inputs.end}}',
          minCapacity: '{{inputs.minCapacity}}',
        },
        onFailure: 'continue',
      },
    ],
    pinned: true,
  },
  {
    slug: 'out_of_office_on',
    title: 'Out of office on',
    description:
      'Set an absence, your status to away and a status message. Changes your Nextcloud profile.',
    inputs: [
      { name: 'firstDay', type: 'string', required: true, description: 'First day, YYYY-MM-DD' },
      { name: 'lastDay', type: 'string', required: true, description: 'Last day, YYYY-MM-DD' },
      {
        name: 'message',
        type: 'string',
        required: true,
        description: 'Absence message shown to others',
      },
      {
        name: 'status',
        type: 'string',
        description: 'Short label, also the status message',
        default: 'Out of office',
      },
      { name: 'replacementUserId', type: 'string', description: 'Who stands in for you' },
    ],
    steps: [
      {
        id: 'absence',
        tool: 'set_out_of_office',
        arguments: {
          firstDay: '{{inputs.firstDay}}',
          lastDay: '{{inputs.lastDay}}',
          status: '{{inputs.status}}',
          message: '{{inputs.message}}',
          replacementUserId: '{{inputs.replacementUserId}}',
        },
      },
      {
        id: 'status',
        tool: 'set_user_status',
        arguments: { statusType: 'away' },
        onFailure: 'continue',
      },
      {
        id: 'message',
        tool: 'set_user_status_message',
        arguments: { message: '{{inputs.status}}', statusIcon: '🌴' },
        onFailure: 'continue',
      },
    ],
    pinned: true,
  },
  {
    slug: 'back_at_work',
    title: 'Back at work',
    description: 'Clear your absence and status message and set your status to online.',
    steps: [
      { id: 'absence', tool: 'clear_out_of_office', onFailure: 'continue' },
      {
        id: 'status',
        tool: 'set_user_status',
        arguments: { statusType: 'online' },
        onFailure: 'continue',
      },
      { id: 'message', tool: 'clear_user_status_message', onFailure: 'continue' },
    ],
    pinned: true,
  },
  {
    slug: 'instance_health_check',
    title: 'Instance health check (admin)',
    description:
      'For admins: server status, setup checks, core integrity and the enabled apps. Steps an account without admin rights cannot run are reported as failed.',
    steps: [
      { id: 'status', tool: 'system_status', onFailure: 'continue' },
      { id: 'setup', tool: 'run_setup_checks', onFailure: 'continue' },
      { id: 'integrity', tool: 'check_core_integrity', onFailure: 'continue' },
      { id: 'apps', tool: 'list_apps', arguments: { filter: 'enabled' }, onFailure: 'continue' },
    ],
    pinned: true,
  },
];

export const BUILTIN_SEQUENCES: readonly Sequence[] = definitions.map((d) => ({
  ...SequenceDefinitionSchema.parse(d),
  source: 'builtin' as const,
}));
