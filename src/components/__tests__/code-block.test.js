jest.mock( '@codemirror/lang-javascript', () => ( {
	javascript: jest.fn( ( options ) => ( {
		language: 'javascript',
		options,
	} ) ),
} ) );

import { javascript } from '@codemirror/lang-javascript';
import { getLanguageExtension } from '../code-block';

describe( 'CodeBlock language loading', () => {
	beforeEach( () => {
		javascript.mockClear();
	} );

	test( 'does not load a parser for an unknown language', async () => {
		await expect( getLanguageExtension( 'plaintext' ) ).resolves.toBeNull();
		expect( javascript ).not.toHaveBeenCalled();
	} );

	test( 'loads a canonical parser only when requested', async () => {
		await expect( getLanguageExtension( 'js' ) ).resolves.toEqual( {
			language: 'javascript',
			options: undefined,
		} );
		expect( javascript ).toHaveBeenCalledWith();
	} );

	test( 'passes alias options to the deferred parser factory', async () => {
		await getLanguageExtension( ' TSX ' );

		expect( javascript ).toHaveBeenCalledWith( {
			jsx: true,
			typescript: true,
		} );
	} );
} );
