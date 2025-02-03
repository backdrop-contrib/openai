$(document).ready(function () {
  if (Backdrop.settings.openaiAlt) {
    var fid = Backdrop.settings.openaiAlt.fid;
    var fieldName = Backdrop.settings.openaiAlt.field_name;
    var delta = Backdrop.settings.openaiAlt.delta;
    var wrapperId = Backdrop.settings.openaiAlt.wrapper_id;

    console.log("✅ Auto-generation script running...");
    console.log("🖼️ File ID:", fid);
    console.log("📂 Field Name:", fieldName);
    console.log("🔄 Wrapper ID:", wrapperId);

    $.ajax({
      url: Backdrop.settings.basePath + 'openai-alt/generate-alt-text',
      type: 'POST',
      data: {
        fid: fid,
        field_name: fieldName,
        delta: delta,
      },
      success: function (response) {
        console.log("✅ AJAX request successful! Full response:", response);
        if (response.status === "success" && response.alt_text) {
          $("input[name='" + fieldName + "[und][" + delta + "][alt]']").val(response.alt_text);
          console.log("✅ Alt text successfully inserted.");
        } else {
          console.error("❌ No valid alt text received.");
        }
      },
      error: function (xhr, status, error) {
        console.error("❌ AJAX request failed:", status, error);
      }
    });
  }
});
