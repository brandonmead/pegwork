/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import {
	conditionText,
	recipientText,
	stepSummary,
	untilText,
} from '../labels';

const fields = [
	{ path: 'record.amount', label: 'Amount', type: 'number' },
	{ path: 'record.donor_name', label: 'Donor name', type: 'text' },
	{
		path: 'record.interest',
		label: 'Interest',
		type: 'select',
		options: [ { value: 'events', label: 'Events' } ],
	},
];

describe( 'conditionText', () => {
	it( 'reads a number as itself, quotes text, and names options', () => {
		expect(
			conditionText(
				{ var: 'record.amount', op: 'gte', value: 250 },
				fields
			)
		).toBe( 'Amount is at least 250' );
		expect(
			conditionText(
				{ var: 'record.donor_name', op: 'eq', value: '250' },
				fields
			)
		).toBe( 'Donor name is "250"' );
		expect(
			conditionText(
				{ var: 'record.interest', op: 'eq', value: 'events' },
				fields
			)
		).toBe( 'Interest is Events' );
	} );
} );

describe( 'wait until and role recipients', () => {
	const dates = [ { path: 'record.due_on', label: 'Due on', type: 'date' } ];
	const config = {
		types: [],
		relationships: [],
		roles: [ { slug: 'editor', name: 'Editor' } ],
	};

	it( 'says the target as WaitUntil::describe() does', () => {
		expect(
			stepSummary(
				{
					type: 'wait',
					until: {
						var: 'record.due_on',
						offset: '-P3D',
						at: '09:00',
					},
				},
				dates,
				config
			)
		).toBe( 'Wait until 3 days before Due on at 09:00' );
		expect(
			untilText( { var: 'record.due_on', offset: '+PT2H' }, dates )
		).toBe( '2 hours after Due on at 09:00' );
		expect(
			untilText(
				{ var: 'record.reminded_on', offset: 'P0D', at: '07:30' },
				dates
			)
		).toBe( 'record.reminded_on at 07:30' );
		expect(
			untilText( { var: 'record.due_on', offset: 'P1W' }, dates )
		).toBe( '1 week after Due on at 09:00' );
	} );

	it( 'names roles on the card, never members', () => {
		expect(
			recipientText(
				'{role:editor}; {role:barn_reviewer}, {option:s.email}',
				config
			)
		).toBe(
			'everyone with role Editor and barn_reviewer, {option:s.email}'
		);
		expect( recipientText( '{{record.email}}', config ) ).toBe(
			'{{record.email}}'
		);
	} );
} );
