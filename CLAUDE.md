## API-/Lib-Snapshot (Context7, 2026-09-07)

- WordPress: mindestens 6.5 · PHP: mindestens 8.1.
- `image_editor_output_format` ordnet Quell-MIME-Typen dem Zielformat zu.
- `WP_Image_Editor::get_output_format()` gleicht MIME-Typ und Dateiendung ab und fällt auf das Quellformat zurück, wenn der gewählte Editor das Ziel nicht unterstützt.
- `wp_create_image_subsizes()` konvertiert das Vollbild beim Speichern. Bei Bildern über der Skalierungsschwelle hängt die Konvertierung deshalb vom erfolgreichen Resize-Schritt ab.
