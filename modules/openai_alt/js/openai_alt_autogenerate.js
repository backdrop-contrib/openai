(function ($) {
  $(document).ready(function () {
    /**
     * Ensure the correct alt field is wrapped properly.
     */
    function wrapAltTextFields() {
      $("input[name$='[alt]']").each(function () {
        var $altField = $(this).closest(".form-item");

        //console.log("🛠 Wrapping ALT field:", $altField); // Debugging

        if (!$altField.parent().hasClass("ai-alt-field-wrapper")) {
          $altField.wrap('<div class="ai-alt-field-wrapper"></div>');
          //console.log("✅ Wrapped:", $altField);
        }
      });
    }

    /**
     * Automatically trigger alt text generation on page load if needed.
     */
    function triggerAutoGenerationIfNeeded() {
      if (Backdrop.settings.openaiAlt) {
        var fid = Backdrop.settings.openaiAlt.fid;
        var fieldName = Backdrop.settings.openaiAlt.field_name;
        var delta = Backdrop.settings.openaiAlt.delta;

        // Trigger AJAX call to generate Alt text
        $.ajax({
          url: Backdrop.settings.basePath + "openai-alt/generate-alt-text",
          type: "POST",
          data: { fid: fid, field_name: fieldName, delta: delta },
          success: function (response) {
            if (response.status === "success" && response.alt_text) {
              var $altField = $("input[name='" + fieldName + "[und][" + delta + "][alt]']");
              $altField.val(response.alt_text).trigger("change");

              // Ensure Backdrop recognizes the update
              setTimeout(function () {
                Backdrop.attachBehaviors();
              }, 500);
            }
          },
          error: function (xhr, status, error) {
            console.error("❌ AJAX request failed:", status, error);
          },
        });
      }
    }

    /**
     * Handle AJAX responses for file uploads & alt text generation.
     */
    $(document).ajaxComplete(function (event, xhr, settings) {
      if (settings.url.includes("file/ajax")) {
        setTimeout(function () {
          if (Backdrop.settings.openaiAlt) {
            triggerAutoGenerationIfNeeded();
          }
        }, 500);
      }

      // Handling response from alt text generation
      if (settings.url.includes("openai-alt/generate-alt-text")) {
        try {
          var response = JSON.parse(xhr.responseText);
          if (response.status === "success" && response.alt_text) {
            var $altField = $("input[name^='field_image'][name$='[alt]']");
            $altField.val(response.alt_text).trigger("change");

            // Ensure Backdrop recognizes the update
            setTimeout(function () {
              Backdrop.attachBehaviors();
            }, 500);
          }
        } catch (e) {
          console.error("Error processing OpenAI alt text response:", e);
        }
      }
    });

    /**
     * Handle image removal events.
     */
    $(document).on("click", ".file-remove-button", function () {
      Backdrop.settings.openaiAlt = null; // Reset settings to allow new uploads
    });

    // **Run initial setup**
    wrapAltTextFields();
    triggerAutoGenerationIfNeeded(); // Ensure auto-generation is triggered if needed
  });
})(jQuery);
