/**
 * @author Nischay Nahata
 */

jQuery(document).ready( function() {
    // Default tooltip position.
    var ttpos = $.ui.tooltip.prototype.options.position;
	var results = {};

	function escapeHtml( value ) {
		return String( value == null ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

    // Autocomplete widget extension to provide description
    // tooltips.
	$.widget( "app.autocomplete", $.ui.autocomplete, {

		_create: function() {

			this._super();
            
            // After the menu has been created, apply the tooltip
            // widget. The "items" option selects menu items with
            // a title attribute, the position option moves the tooltip
            // to the right of the autocomplete dropdown.
            this.menu.element.tooltip({
                items: "li",
                position: $.extend( {}, ttpos, {
                    my: "left+12",
                    at: "right"
                }),
				content: function() {
					var tooltipHtml = '';
					curr_id = $(this).attr('id');
					$.each(results, function (index, item) {
						if ( curr_id == item.id ) {
							var abstractText = String( item.abstract == null ? '' : item.abstract );
							var article_abstract = abstractText.substr( 0, 255 );
							if ( abstractText.length > 255 ) {
								article_abstract += "...";
							}
							tooltipHtml = '<div><h4>'+ escapeHtml( item.value ) +'</h4>Authors: '+ escapeHtml( item.authors )
								+ '<br>Year: '+ escapeHtml( item.year )
								+ '<br><br><b>Abstract:</b><br><p>'+ escapeHtml( article_abstract ) +'</p></div>';
							return false;
						}
					});
					return tooltipHtml;
				}
            });
        },

		// Clean up the tooltip widget when the autocomplete is
        // destroyed.
        _destroy: function() {
            this.menu.element.tooltip( "destroy" );
            this._super();
        },

        // Set the title attribute as the "item.desc" value.
        // This becomes the tooltip content.
        _renderItem: function( ul, item ) {
            return this._super( ul, item )
                .attr( "id", item.id );
        }
    });

	$(".mendeley_input").autocomplete({
		autoFocus: true,
		search: function( event, ui ) {
			$( ".mendeley_input" ).addClass( 'loading' );
		},
		source: function( request, response ) {
			 $.ajax({
				url: wgScriptPath + '/api.php?action=pfmendeley&format=json',
				dataType: "json",
				data: request,
				success: function(data){
					results = data.result.autocomplete_results;
					response(data.result.autocomplete_results);
					$( ".mendeley_input" ).removeClass( 'loading' );
				}
			});
		},
		minLength: 2,
		select: function(event, ui) {
			$.each(ui.item, function (i, v) {
				if ( v == null ) {
					return;
				}
				if ( $( 'input.pfTokens.mendeley_input_' + i ).length == 1 ) {
					values = v.split( ", " );
					tokens = new pf.select2.tokens();
					delimiter = tokens.getDelimiter( $( 'input.pfTokens.mendeley_input_' + i ) );
					values = values.join( delimiter );
					$( 'input.pfTokens.mendeley_input_' + i ).val( values );
					tokens.refresh( $( 'input.pfTokens.mendeley_input_' + i ) );
				} else {
					$('.mendeley_input_' + i ).val( v );
					$('.mendeley_input_' + i ).html( v );
				}
			} );
		}
	}).off('blur').on('blur', function() {
		if(document.hasFocus()) {
			$('ul.ui-autocomplete').hide();
		}
	});
});