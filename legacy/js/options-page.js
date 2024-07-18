

jQuery(function ($) {
	const { __, _x, _n, _nx } = wp.i18n;
	$('#blc-tabs').tabs();


	//Refresh the avg. load display every 10 seconds
	function blcUpdateLoad() {
		$.get(
			ajaxurl,
			{
				'action': 'blc_current_load'
			}
		).done(
			data => $('#wsblc_current_load').html(data)
		).fail(
			() => $('#wsblc_current_load').html(__('[ Network error ]', 'broken-link-checker'))
		).always(
			//This ensure that the request do not pile up ( versus setInterval )
			 () => setTimeout(blcUpdateLoad, 10000)
		);
	}
	//Only do it if load limiting is available on this server, though.
	if ($('#wsblc_current_load').length > 0) {
		blcUpdateLoad();
	}


	var toggleButton = $('#blc-debug-info-toggle');

	toggleButton.click(function () {

		var box = $('#blc-debug-info');
		box.toggle();
		if (box.is(':visible')) {
			toggleButton.text(__('Hide debug info', 'broken-link-checker'));
		} else {
			toggleButton.text(__('Show debug info', 'broken-link-checker'));
		}

	});

	$('#toggle-broken-link-css-editor').click(function () {
		var box = $('#broken-link-css-wrap').toggleClass('hidden');

		$.cookie(
			box.attr('id'),
			box.hasClass('hidden') ? '0' : '1',
			{
				expires: 365
			}
		);

		return false;
	});

	$('#toggle-removed-link-css-editor').click(function () {
		var box = $('#removed-link-css-wrap').toggleClass('hidden');

		$.cookie(
			box.attr('id'),
			box.hasClass('hidden') ? '0' : '1',
			{
				expires: 365
			}
		);

		return false;
	});

	//Show/hide per-module settings
	$('.toggle-module-settings').click(function () {
		var settingsBox = $(this).parent().find('.module-extra-settings');
		if (settingsBox.length > 0) {
			settingsBox.toggleClass('hidden');
			$.cookie(
				settingsBox.attr('id'),
				settingsBox.hasClass('hidden') ? '0' : '1',
				{
					expires: 365
				}
			);
		}
		return false;
	});

	//When the user ticks the "Custom fields" box, display the field list input
	//so that they notice that they need to enter the field names.
	$('#module-checkbox-custom_field').click(function () {
		var box = $(this);
		var fieldList = $('#blc_custom_fields');
		if (box.is(':checked') && ($.trim(fieldList.val()) == '')) {
			$('#module-extra-settings-custom_field').removeClass('hidden');
		}
	});

	//When the user ticks the "Custom fields" box, display the field list input
	//so that they notice that they need to enter the field names.
	$('#module-checkbox-acf_field').click(function () {
		var box = $(this);
		var fieldList = $('#blc_acf_fields');
		if (box.is(':checked') && ($.trim(fieldList.val()) == '')) {
			$('#module-extra-settings-acf_field').removeClass('hidden');
		}
	});

	//Handle the "Recheck" button
	$('#start-recheck').click(function () {
		$('#recheck').val('1'); //populate the hidden field
		$('#link_checker_options input[name="submit"]').click(); //.submit() didn't work for some reason
	});

	//Enable/disable log-related options depending on whether "Enable logging" is on.
	function blcToggleLogOptions() {
		$('.blc-logging-options')
			.find('input,select')
			.prop('disabled', !$('#logging_enabled').is(':checked'));
	}

	blcToggleLogOptions();
	$('#logging_enabled').change(blcToggleLogOptions);


	//Enable/disable log-related options depending on whether "Enable logging" is on.
	function blcToggleCookiesptions() {
		$('.blc-cookie-options')
			.find('input,select')
			.prop('disabled', !$('#cookies_enabled').is(':checked'));
	}

	blcToggleCookiesptions();
	$('#cookies_enabled').change(blcToggleCookiesptions);

	//
	$('#target_resource_usage').change(function () {
		$('#target_resource_usage_percent').text($(this).val() + '%')
	});
});


