(function ($) {
  $(document).ready(function () {
    /**
     * Wrap ALT fields in a container if not already done.
     */
    function wrapAltTextFields() {
      $("input[name$='[alt]']").each(function () {
        var $altField = $(this).closest(".form-item");
        if (!$altField.parent().hasClass("ai-alt-field-wrapper")) {
          $altField.wrap('<div class="ai-alt-field-wrapper"></div>');
        }
      });
    }

    /**
     * Generate alt text for each item that was flagged in Backdrop.settings.openaiAlt.
     * (We only add items with empty alt in the PHP code, so we won't overwrite existing alt.)
     */
    function triggerAutoGenerationForAll() {
      if (Backdrop.settings.openaiAlt) {
        $.each(Backdrop.settings.openaiAlt, function (key, item) {
          // item = { fid, field_name, delta, target_id, ... }
          if (item.fid && item.field_name !== undefined && item.delta !== undefined) {
            generateAltText(item.fid, item.field_name, item.delta);
          }
        });
      }
    }

    /**
     * Helper: Actually call the endpoint, then place alt text in the correct input.
     */
    function generateAltText(fid, fieldName, delta) {
      $.ajax({
        url: Backdrop.settings.basePath + "openai-alt/generate-alt-text",
        type: "POST",
        data: { fid: fid, field_name: fieldName, delta: delta },
        success: function (response) {
          if (response.status === "success" && response.alt_text) {
            // 1) Insert the result into the <input> for this field/delta.
            var selector = "input[name='" + fieldName + "[und][" + delta + "][alt]']";
            $(selector).val(response.alt_text).trigger("change");

            // 2) Remove this item from openaiAlt so it won't auto-regenerate again.
            var key = fieldName + ":" + delta;
            if (Backdrop.settings.openaiAlt && Backdrop.settings.openaiAlt[key]) {
              delete Backdrop.settings.openaiAlt[key];
            }
          }
        }
      });
    }

    /**
     * After any AJAX that includes "file/ajax" (i.e. new image added),
     * wait a moment, then try generating alt text for newly added items.
     */
    $(document).ajaxComplete(function (event, xhr, settings) {
      if (settings.url.includes("file/ajax")) {
        setTimeout(triggerAutoGenerationForAll, 500);
      }
    });

    /**
     * Optional: If you remove an image, you might want to remove
     * that item from openaiAlt. Only do so if you have data attributes
     * for the remove button. Otherwise, leaving them won't matter
     * because we only run alt generation for items with an empty alt
     * (and that item is gone entirely anyway).
     */
    $(document).on("click", ".file-remove-button", function () {
      // For a multi-value field, you might parse the delta from $(this).data('delta').
      // For now, we can just do:
      //   Backdrop.settings.openaiAlt = null;
      // But that nixes *all* items. So be careful.
    });

    // On page load, wrap all existing ALT fields, then generate for any that need it.
    wrapAltTextFields();
    triggerAutoGenerationForAll();
  });
})(jQuery);
