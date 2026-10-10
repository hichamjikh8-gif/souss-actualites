( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var ServerSideRender = serverSideRender.default || serverSideRender;

	var games = ( window.sgtdBlockData && window.sgtdBlockData.games ) || [];

	blocks.registerBlockType( 'sa/spot-the-difference', {
		title: __( 'Trouvez les différences', 'sa-spot-the-difference' ),
		icon: 'admin-customizer',
		category: 'widgets',
		attributes: {
			gameId: { type: 'number', default: 0 },
		},

		edit: function ( props ) {
			var gameId = props.attributes.gameId;

			var options = [ { label: __( '— Choisir un jeu publié —', 'sa-spot-the-difference' ), value: 0 } ].concat(
				games.map( function ( game ) {
					return { label: game.title, value: game.id };
				} )
			);

			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Jeu à afficher', 'sa-spot-the-difference' ) },
					el( SelectControl, {
						label: __( 'Choisir un jeu', 'sa-spot-the-difference' ),
						value: gameId,
						options: options,
						onChange: function ( value ) {
							props.setAttributes( { gameId: parseInt( value, 10 ) || 0 } );
						},
					} )
				)
			);

			var body;
			if ( ! gameId ) {
				body = el(
					'p',
					{ className: 'sgtd-admin-notice', style: { padding: '1em', border: '1px dashed #d40000' } },
					__( 'Choisissez un jeu "Trouvez les différences" dans le panneau de droite.', 'sa-spot-the-difference' )
				);
			} else {
				body = el( ServerSideRender, {
					block: 'sa/spot-the-difference',
					attributes: { gameId: gameId },
				} );
			}

			return el( element.Fragment, {}, inspector, body );
		},

		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender );
