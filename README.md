# Assistant Caisse Intelligent avec Groq AI

Un **chatbot ultra-rapide et intelligent** qui répond en langage naturel à toutes tes questions sur ta caisse :  
Produits, ventes du jour, meilleurs clients, stock, factures… tout !

Propulsé par **Groq** (Llama 3.3 70B ou 90B) → réponses en moins d’1 seconde !

Aucun framework, 100 % PHP + HTML + CSS + JS → fonctionne sur **Laragon, XAMPP, ou n’importe quel hébergement PHP/MySQL**.

Démo en direct (quand tu l’héberges) → ouvre simplement `index.html`

## Fonctionnalités

- Interface chat moderne et fluide
- Compréhension du langage naturel (français marocain compris !)
- Génération automatique et sécurisée de requêtes SQL
- Protection contre les injections (seulement SELECT autorisées)
- Diagnostic automatique si aucun résultat ("table vide", "filtre trop strict", etc.)
- Affichage joli des résultats en tableau HTML avec émoticons
- Suggestions de questions intégrées
- Compatible mobile

## Installation

1. **Télécharge ou clone ce projet**
   git clone https://github.com/tonpseudo/assistant-caisse-groq.git
   cd assistant-caisse-groq

2. **Copie le fichier d’environnement**
cp .env.example .env
3. **Édite le fichier .env avec tes infosproperties**
DB_HOST=localhost
DB_NAME=projetmed          ← ta base de données (doit exister)
DB_USER=root
DB_PASS=

GROQ_API_KEY=gsk_ton_vraie_cle_ici     ← obligatoire ! gratuit sur https://console.groq.com
GROQ_MODEL=llama-3.3-70b-versatile     ← recommandé (ou llama-3.2-90b-text-preview)

4. **Lance avec Laragon / XAMPP / EasyPHP**
Place le dossier dans www/ ou htdocs/ → ouvre http://localhost/assistant-caisse-groq

