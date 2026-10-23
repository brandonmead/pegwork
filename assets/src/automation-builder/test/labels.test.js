/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { conditionText } from '../labels';

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
