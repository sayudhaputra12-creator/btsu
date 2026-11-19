INSERT INTO settings (setting_key, setting_value) VALUES
  ('default_loan_days', '14'),
  ('max_extensions', '2')
ON CONFLICT (setting_key) DO NOTHING;

INSERT INTO right_categories (name) VALUES
  ('Hak Milik'),
  ('Hak Guna Usaha'),
  ('Hak Guna Bangunan'),
  ('Hak Pakai'),
  ('Hak Pengelolaan'),
  ('Hak Wakaf'),
  ('Hak Milik Satuan Rumah Susun')
ON CONFLICT (name) DO NOTHING;

INSERT INTO units (name) VALUES
  ('Seksi Penetapan Hak dan Pendaftaran'),
  ('Seksi Survei dan Pemetaan'),
  ('Subbagian Tata Usaha')
ON CONFLICT (name) DO NOTHING;

INSERT INTO districts (name) VALUES
  ('Banyumanik'), ('Candisari'), ('Gajahmungkur'), ('Gayamsari'),
  ('Genuk'), ('Gunungpati'), ('Mijen'), ('Ngaliyan'), ('Pedurungan'),
  ('Semarang Barat'), ('Semarang Selatan'), ('Semarang Tengah'),
  ('Semarang Timur'), ('Semarang Utara'), ('Tembalang'), ('Tugu')
ON CONFLICT (name) DO NOTHING;
INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Banyumanik' AS name UNION ALL SELECT 'Gedawang' UNION ALL SELECT 'Jabungan' UNION ALL
  SELECT 'Ngesrep' UNION ALL SELECT 'Padangsari' UNION ALL SELECT 'Pedalangan' UNION ALL
  SELECT 'Pudakpayung' UNION ALL SELECT 'Srondol Kulon' UNION ALL SELECT 'Srondol Wetan' UNION ALL
  SELECT 'Sumurboto' UNION ALL SELECT 'Tinjomoyo'
) v WHERE d.name = 'Banyumanik'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Candi' AS name UNION ALL SELECT 'Jatingaleh' UNION ALL SELECT 'Jomblang' UNION ALL
  SELECT 'Kaliwiru' UNION ALL SELECT 'Karanganyar Gunung' UNION ALL SELECT 'Tegalsari' UNION ALL
  SELECT 'Wonotingal'
) v WHERE d.name = 'Candisari'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Bendanduwur' AS name UNION ALL SELECT 'Bendan Ngisor' UNION ALL SELECT 'Bendungan' UNION ALL
  SELECT 'Gajahmungkur' UNION ALL SELECT 'Karangrejo' UNION ALL SELECT 'Lempongsari' UNION ALL
  SELECT 'Petompon' UNION ALL SELECT 'Sampangan'
) v WHERE d.name = 'Gajahmungkur'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Gayamsari' AS name UNION ALL SELECT 'Kaligawe' UNION ALL SELECT 'Pandean Lamper' UNION ALL
  SELECT 'Sambirejo' UNION ALL SELECT 'Sawahbesar' UNION ALL SELECT 'Siwalan' UNION ALL
  SELECT 'Tambakrejo'
) v WHERE d.name = 'Gayamsari'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Bangetayu Kulon' AS name UNION ALL SELECT 'Bangetayu Wetan' UNION ALL SELECT 'Banjardowo' UNION ALL
  SELECT 'Gebangsari' UNION ALL SELECT 'Genuksari' UNION ALL SELECT 'Karangroto' UNION ALL SELECT 'Kudu' UNION ALL
  SELECT 'Muktiharjo Lor' UNION ALL SELECT 'Penggaron Lor' UNION ALL SELECT 'Sembungharjo' UNION ALL
  SELECT 'Terboyo Kulon' UNION ALL SELECT 'Terboyo Wetan' UNION ALL SELECT 'Trimulyo'
) v WHERE d.name = 'Genuk'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Cepoko' AS name UNION ALL SELECT 'Gunungpati' UNION ALL SELECT 'Jatirejo' UNION ALL
  SELECT 'Kalisegoro' UNION ALL SELECT 'Kandri' UNION ALL SELECT 'Mangunsari' UNION ALL SELECT 'Ngijo' UNION ALL
  SELECT 'Nongkosawit' UNION ALL SELECT 'Pakintelan' UNION ALL SELECT 'Patemon' UNION ALL SELECT 'Plalangan' UNION ALL
  SELECT 'Pongangan' UNION ALL SELECT 'Sadeng' UNION ALL SELECT 'Sekaran' UNION ALL SELECT 'Sukorejo' UNION ALL
  SELECT 'Sumurejo'
) v WHERE d.name = 'Gunungpati'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Bubakan' AS name UNION ALL SELECT 'Cangkiran' UNION ALL SELECT 'Jatibarang' UNION ALL
  SELECT 'Jatisari' UNION ALL SELECT 'Karangmalang' UNION ALL SELECT 'Kedungpane' UNION ALL SELECT 'Mijen' UNION ALL
  SELECT 'Ngadirgo' UNION ALL SELECT 'Pesantren' UNION ALL SELECT 'Polaman' UNION ALL SELECT 'Purwosari' UNION ALL
  SELECT 'Tambangan' UNION ALL SELECT 'Wonolopo' UNION ALL SELECT 'Wonoplumbon'
) v WHERE d.name = 'Mijen'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Bambankerep' AS name UNION ALL SELECT 'Bringin' UNION ALL SELECT 'Gondoriyo' UNION ALL
  SELECT 'Kalipancur' UNION ALL SELECT 'Ngaliyan' UNION ALL SELECT 'Podorejo' UNION ALL SELECT 'Purwoyoso' UNION ALL
  SELECT 'Tambakaji' UNION ALL SELECT 'Wonosari' UNION ALL SELECT 'Wates'
) v WHERE d.name = 'Ngaliyan'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Gemah' AS name UNION ALL SELECT 'Kalicari' UNION ALL SELECT 'Muktiharjo Kidul' UNION ALL
  SELECT 'Palebon' UNION ALL SELECT 'Pedurungan Kidul' UNION ALL SELECT 'Pedurungan Lor' UNION ALL
  SELECT 'Pedurungan Tengah' UNION ALL SELECT 'Penggaron Kidul' UNION ALL SELECT 'Plamongan Sari' UNION ALL
  SELECT 'Tlogomulyo' UNION ALL SELECT 'Tlogosari Kulon' UNION ALL SELECT 'Tlogosari Wetan'
) v WHERE d.name = 'Pedurungan'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Bojongsalaman' AS name UNION ALL SELECT 'Bongsari' UNION ALL SELECT 'Cabean' UNION ALL
  SELECT 'Gisikdrono' UNION ALL SELECT 'Kalibanteng Kidul' UNION ALL SELECT 'Kalibanteng Kulon' UNION ALL
  SELECT 'Karangayu' UNION ALL SELECT 'Kembangarum' UNION ALL SELECT 'Krapyak' UNION ALL SELECT 'Krobokan' UNION ALL
  SELECT 'Manyaran' UNION ALL SELECT 'Ngemplak Simongan' UNION ALL SELECT 'Salaman Mloyo' UNION ALL
  SELECT 'Tambakharjo' UNION ALL SELECT 'Tawang Mas' UNION ALL SELECT 'Tawangsari'
) v WHERE d.name = 'Semarang Barat'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Barusari' AS name UNION ALL SELECT 'Bulustalan' UNION ALL SELECT 'Lamper Kidul' UNION ALL
  SELECT 'Lamper Lor' UNION ALL SELECT 'Lamper Tengah' UNION ALL SELECT 'Mugassari' UNION ALL
  SELECT 'Peterongan' UNION ALL SELECT 'Pleburan' UNION ALL SELECT 'Randusari' UNION ALL SELECT 'Wonodri'
) v WHERE d.name = 'Semarang Selatan'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Bangunharjo' AS name UNION ALL SELECT 'Brumbungan' UNION ALL SELECT 'Gabahan' UNION ALL
  SELECT 'Jagalan' UNION ALL SELECT 'Karangkidul' UNION ALL SELECT 'Kauman' UNION ALL SELECT 'Kembangsari' UNION ALL
  SELECT 'Kranggan' UNION ALL SELECT 'Miroto' UNION ALL SELECT 'Pandansari' UNION ALL SELECT 'Pekunden' UNION ALL
  SELECT 'Pendrikan Kidul' UNION ALL SELECT 'Pendrikan Lor' UNION ALL SELECT 'Purwodinatan' UNION ALL SELECT 'Sekayu'
) v WHERE d.name = 'Semarang Tengah'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Bugangan' AS name UNION ALL SELECT 'Karangtempel' UNION ALL SELECT 'Karangturi' UNION ALL
  SELECT 'Kebonagung' UNION ALL SELECT 'Kemijen' UNION ALL SELECT 'Mlatibaru' UNION ALL SELECT 'Mlatiharjo' UNION ALL
  SELECT 'Rejomulyo' UNION ALL SELECT 'Rejosari' UNION ALL SELECT 'Sarirejo'
) v WHERE d.name = 'Semarang Timur'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Bandarharjo' AS name UNION ALL SELECT 'Bulu Lor' UNION ALL SELECT 'Dadapsari' UNION ALL
  SELECT 'Kuningan' UNION ALL SELECT 'Panggung Kidul' UNION ALL SELECT 'Panggung Lor' UNION ALL
  SELECT 'Plombokan' UNION ALL SELECT 'Purwosari' UNION ALL SELECT 'Tanjung Mas'
) v WHERE d.name = 'Semarang Utara'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Bulusan' AS name UNION ALL SELECT 'Jangli' UNION ALL SELECT 'Kedungmundu' UNION ALL
  SELECT 'Kramas' UNION ALL SELECT 'Mangunharjo' UNION ALL SELECT 'Meteseh' UNION ALL SELECT 'Rowosari' UNION ALL
  SELECT 'Sambiroto' UNION ALL SELECT 'Sendangguwo' UNION ALL SELECT 'Sendangmulyo' UNION ALL SELECT 'Tandang' UNION ALL
  SELECT 'Tembalang'
) v WHERE d.name = 'Tembalang'
ON CONFLICT (district_id, name) DO NOTHING;

INSERT INTO villages (district_id, name)
SELECT d.id, v.name FROM districts d
CROSS JOIN (
  SELECT 'Jerakah' AS name UNION ALL SELECT 'Karanganyar' UNION ALL SELECT 'Mangkang Kulon' UNION ALL
  SELECT 'Mangkang Wetan' UNION ALL SELECT 'Mangunharjo' UNION ALL SELECT 'Randu Garut' UNION ALL SELECT 'Tugurejo'
) v WHERE d.name = 'Tugu'
ON CONFLICT (district_id, name) DO NOTHING;
