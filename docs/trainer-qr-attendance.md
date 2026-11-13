# Trainer gym QR attendance

Trainers use the administrator's existing gym attendance QR code from **Trainer Dashboard → Scan gym QR code**, or the scanner link on their profile and attendance page. The code is still issued by `attendance_qr_token.php`, expires under the existing 24-hour policy, and is validated by the server. Trainer identity comes only from the authenticated trainer account. The scanner follows the member flow's limit of 12 requests per trainer per minute.

Apply `database/trainer_qr_attendance.sql` after `database/attendance_qr.sql`. It allows a trainer attendance row to exist without a scheduled availability slot and adds a per-trainer QR replay record. Existing trainer attendance rows are preserved. When a trainer has an active scheduled shift at scan time, that shift is used; otherwise the check-in is recorded as a QR attendance entry without schedule times. Both kinds appear in the trainer attendance view and administrator trainer attendance report.

The scanner asks for camera access after the trainer presses **Start camera**. Camera access requires HTTPS or localhost. Preview and confirmation use the same current gym token; its first successful scan is replay-protected, with the same token permitted for the matching check-out.
