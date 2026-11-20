# Google Drive Sync Setup (Roman Urdu)

Yeh guide lab ke apne Google account se Drive sync connect karne ke liye hai.
Data sirf lab ke account ke appDataFolder mein jata hai — koi paid cloud nahi.

## 1. Google Cloud Project

1. Browser mein jao: https://console.cloud.google.com/
2. Naya project banao (naam: `Pakistani-Lab-LIMS` ya koi bhi)
3. Project select karo

## 2. Drive API enable karo

1. Left menu → **APIs & Services** → **Library**
2. Search: `Google Drive API`
3. **Enable** dabao

## 3. OAuth consent screen

1. **APIs & Services** → **OAuth consent screen**
2. User type: **External**
3. App name: `Pakistani Lab LIMS`
4. Support email apna dalo
5. Scopes add karo:
   - `https://www.googleapis.com/auth/drive.appdata`
   - `https://www.googleapis.com/auth/drive.file`
6. Test users mein apna Gmail add karo
7. Status **In production** kar sakte ho (verification zaroori nahi in scopes ke liye)

## 4. OAuth Client IDs

### Desktop (Windows / Tauri)
1. **Credentials** → **Create Credentials** → **OAuth client ID**
2. Application type: **Desktop app**
3. Name: `LIMS Windows`
4. Client ID + Client Secret download / copy karke `.env` mein rakho:
   ```
   GOOGLE_CLIENT_ID=....apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=....
   ```

### Android (baad mein)
1. Type: **Android**
2. Package name (example): `com.pakistanilab.lims`
3. SHA-1 debug + release dono add karo

## 5. App mein connect

1. Settings → Sync → **Connect Google Drive**
2. Browser open hoga → lab ka Google account choose karo → Allow
3. Token securely save ho jayega (kabhi git mein commit mat karna)

## 6. Kaam kaise karta hai

- Har device pehle local DB mein save karta hai
- Internet aate hi pending records Drive par chale jate hain
- Doosri device pull karti hai
- Conflict hone par newest version rehti hai; purani finalized report revision ban jati hai
- Offline full kaam chalta rehta hai

## Security

- Sirf `drive.appdata` + `drive.file` scopes
- Patient data public folder mein nahi jata
- Tokens encrypted storage mein
- Kabhi bhi token ya client secret GitHub par mat daalna
