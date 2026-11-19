"use client";

import { useMemo, useState } from "react";

export function DistrictVillageSelect({
  districts,
  villages,
  selectedDistrict,
  selectedVillage,
}: {
  districts: string[];
  villages: Record<string, string[]>;
  selectedDistrict: string;
  selectedVillage: string;
}) {
  const [district, setDistrict] = useState(selectedDistrict);
  const options = useMemo(() => villages[district] || [], [district, villages]);
  return (
    <>
      <label>Kecamatan
        <select name="district" value={district} onChange={(event) => setDistrict(event.target.value)} required>
          <option value="">Pilih kecamatan</option>
          {districts.map((name) => <option key={name}>{name}</option>)}
        </select>
      </label>
      <label>Desa/Kelurahan
        <select name="village" defaultValue={selectedVillage} required>
          <option value="">Pilih kelurahan</option>
          {options.map((name) => <option key={name}>{name}</option>)}
          {selectedVillage && !options.includes(selectedVillage) && <option>{selectedVillage}</option>}
        </select>
        <small>Pilihan kelurahan mengikuti kecamatan yang dipilih.</small>
      </label>
    </>
  );
}

export function DocumentPicker({ documents }: { documents: Array<Record<string, string | number>> }) {
  const [term, setTerm] = useState("");
  const normalized = term.trim().toLocaleLowerCase("id");
  return (
    <>
      <label className="search-field">Cari dokumen
        <input type="search" value={term} onChange={(event) => setTerm(event.target.value)} placeholder="Nomor hak, jenis hak, desa…" />
      </label>
      <div className="document-picker">
        {documents.map((doc) => {
          const label = `${doc.right_number} ${doc.district} ${doc.village} ${doc.category}`;
          if (normalized && !label.toLocaleLowerCase("id").includes(normalized)) return null;
          return (
            <label key={doc.id} className="document-option">
              <input type="checkbox" name="document_ids[]" value={doc.id} />
              <span><strong>{doc.right_number} · {doc.category}</strong><small>{doc.district}, {doc.village}</small></span>
              <span className="checkmark">Pilih</span>
            </label>
          );
        })}
        {!documents.length && <p className="muted">Tidak ada dokumen tersedia. Tambahkan atau aktifkan dokumen terlebih dahulu.</p>}
      </div>
    </>
  );
}

export function PrintButton({ children = "Cetak / Simpan PDF" }: { children?: string }) {
  return <button type="button" className="button" onClick={() => window.print()}>{children}</button>;
}
