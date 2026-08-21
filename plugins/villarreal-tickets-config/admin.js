jQuery(function ($) {
	$(".vcf-color-field").wpColorPicker();

	// Activo / inactivo: muestra u oculta la fila de detalles del partido.
	$(document).on("change", ".vcf-active-toggle", function () {
		var name = $(this).attr("name"); // vcf_match[ID][active]
		var match = name.match(/vcf_match\[(.*?)\]/);
		if (!match) return;
		var id = match[1];
		var $details = $('tr[data-vcf-details-for="' + id + '"]');
		if ($(this).is(":checked")) {
			$details.removeClass("vcf-hidden");
		} else {
			$details.addClass("vcf-hidden");
		}
	});

	// VIP: muestra u oculta los campos de texto/enlace del botón VIP.
	$(document).on("change", ".vcf-vip-toggle", function () {
		var name = $(this).attr("name");
		var match = name.match(/vcf_match\[(.*?)\]/);
		if (!match) return;
		var id = match[1];
		var $fields = $('[data-vcf-vip-for="' + id + '"]');
		if ($(this).is(":checked")) {
			$fields.removeClass("vcf-hidden");
		} else {
			$fields.addClass("vcf-hidden");
		}
	});

	// Más información: muestra u oculta el título/texto del modal.
	$(document).on("change", ".vcf-moreinfo-toggle", function () {
		var name = $(this).attr("name");
		var match = name.match(/vcf_match\[(.*?)\]/);
		if (!match) return;
		var id = match[1];
		var $fields = $('[data-vcf-moreinfo-for="' + id + '"]');
		if ($(this).is(":checked")) {
			$fields.removeClass("vcf-hidden");
		} else {
			$fields.addClass("vcf-hidden");
		}
	});
});
